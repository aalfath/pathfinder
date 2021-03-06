<?php
/**
 * Created by PhpStorm.
 * User: exodus4d
 * Date: 08.02.15
 * Time: 23:48
 */

namespace Controller;
use Controller\Api as Api;
use lib\Config;
use lib\Monolog;
use lib\Socket;
use lib\Util;
use Model;
use DB;

class Controller {

    // cookie specific keys (names)
    const COOKIE_NAME_STATE                         = 'cookie';
    const COOKIE_PREFIX_CHARACTER                   = 'char';

    // log text
    const ERROR_SESSION_SUSPECT                     = 'id: [%45s], ip: [%45s], User-Agent: [%s]';
    const ERROR_TEMP_CHARACTER_ID                   = 'Invalid temp characterId: %s';

    /**
     * @var \Base
     */
    protected $f3;

    /**
     * @var string template for render
     */
    protected $template;

    /**
     * @param string $template
     */
    protected function setTemplate($template){
        $this->template = $template;
    }

    /**
     * @return string
     */
    protected function getTemplate(){
        return $this->template;
    }

    /**
     * get $f3 base object
     * @return \Base
     */
    protected function getF3(){
        return \Base::instance();
    }

    /**
     * event handler for all "views"
     * some global template variables are set in here
     * @param \Base $f3
     * @param $params
     * @return bool
     */
    function beforeroute(\Base $f3, $params): bool {
        // initiate DB connection
        DB\Database::instance()->getDB('PF');

        // init user session
        $this->initSession($f3);

        if($f3->get('AJAX')){
            header('Content-type: application/json');
        }else{
            // js path (build/minified or raw uncompressed files)
            $f3->set('tplPathJs', 'public/js/' . Config::getPathfinderData('version') );

            $this->setTemplate( Config::getPathfinderData('view.index') );
        }

        return true;
    }

    /**
     * event handler after routing
     * -> render view
     * @param \Base $f3
     */
    public function afterroute(\Base $f3){
        if($this->getTemplate()){
            // Ajax calls don´t need a page render..
            // this happens on client side
            echo \Template::instance()->render( $this->getTemplate() );
        }
    }

    /**
     * set change the DB connection
     * @param string $database
     * @return DB\SQL
     */
    protected function getDB($database = 'PF'){
        return DB\Database::instance()->getDB($database);
    }

    /**
     * init new Session handler
     */
    protected function initSession(\Base $f3){
        $sessionCacheKey = $f3->get('SESSION_CACHE');
        $session = null;

        /**
         * callback() for suspect sessions
         * @param $session
         * @param $sid
         * @return bool
         */
        $onSuspect = function($session, $sid){
            self::getLogger('SESSION_SUSPECT')->write( sprintf(
                self::ERROR_SESSION_SUSPECT,
                $sid,
                $session->ip(),
                $session->agent()
            ));
            // .. continue with default onSuspect() handler
            // -> destroy session
            return false;
        };

        if(
            $sessionCacheKey === 'mysql' &&
            $this->getDB('PF') instanceof DB\SQL
        ){
            $session = new DB\SQL\Session($this->getDB('PF'), 'sessions', true, $onSuspect);
        }

    }

    /**
     * get cookies "state" information
     * -> whether user accepts cookies
     * @return bool
     */
    protected function getCookieState(){
        return (bool)count( $this->getCookieByName(self::COOKIE_NAME_STATE) );
    }

    /**
     * search for existing cookies
     * -> either a specific cookie by its name
     * -> or get multiple cookies by their name (search by prefix)
     * @param $cookieName
     * @param bool $prefix
     * @return array
     */
    protected function getCookieByName($cookieName, $prefix = false){
        $data = [];

        if(!empty($cookieName)){
            $cookieData = (array)$this->getF3()->get('COOKIE');
            if($prefix === true){
                // look for multiple cookies with same prefix
                foreach($cookieData as $name => $value){
                    if(strpos($name, $cookieName) === 0){
                        $data[$name] = $value;
                    }
                }
            }elseif( isset($cookieData[$cookieName]) ){
                // look for a single cookie
                $data[$cookieName] = $cookieData[$cookieName];
            }
        }

        return $data;
    }

    /**
     * set/update logged in cookie by character model
     * -> store validation data in DB
     * @param Model\CharacterModel $character
     */
    protected function setLoginCookie(Model\CharacterModel $character){
        if( $this->getCookieState() ){
            $expireSeconds = (int)Config::getPathfinderData('login.cookie_expire');
            $expireSeconds *= 24 * 60 * 60;

            $timezone = $this->getF3()->get('getTimeZone')();
            $expireTime = new \DateTime('now', $timezone);

            // add cookie expire time
            $expireTime->add(new \DateInterval('PT' . $expireSeconds . 'S'));

            // unique "selector" -> to facilitate database look-ups (small size)
            // -> This is preferable to simply using the database id field,
            // which leaks the number of active users on the application
            $selector = bin2hex( openssl_random_pseudo_bytes(12) );

            // generate unique "validator" (strong encryption)
            // -> plaintext set to user (cookie), hashed version of this in DB
            $size = openssl_cipher_iv_length('aes-256-cbc');
            $validator = bin2hex(openssl_random_pseudo_bytes($size) );

            // generate unique cookie token
            $token = hash('sha256', $validator);

            // get unique cookie name for this character
            $name = $character->getCookieName();

            $authData = [
                'characterId'   => $character,
                'selector'      => $selector,
                'token'         => $token,
                'expires'       => $expireTime->format('Y-m-d H:i:s')
            ];

            $authenticationModel = $character->rel('characterAuthentications');
            $authenticationModel->copyfrom($authData);
            $authenticationModel->save();

            $cookieValue = implode(':', [$selector, $validator]);

            // get cookie name -> save new one OR update existing cookie
            $cookieName = 'COOKIE.' . self::COOKIE_PREFIX_CHARACTER . '_' . $name;
            $this->getF3()->set($cookieName, $cookieValue, $expireSeconds);
        }
    }

    /**
     * get characters from given cookie data
     * -> validate cookie data
     * -> validate characters
     * -> cf. Sso->requestAuthorization() ( equivalent DB based login)
     *
     * @param array $cookieData
     * @param bool $checkAuthorization
     * @return Model\CharacterModel[]
     */
    protected function getCookieCharacters($cookieData = [], $checkAuthorization = true){
        $characters = [];

        if(
            $this->getCookieState() &&
            !empty($cookieData)
        ){
            /**
             * @var $characterAuth Model\CharacterAuthenticationModel
             */
            $characterAuth = Model\BasicModel::getNew('CharacterAuthenticationModel');

            $timezone = $this->getF3()->get('getTimeZone')();
            $currentTime = new \DateTime('now', $timezone);

            foreach($cookieData as $name => $value){
                // remove invalid cookies
                $invalidCookie = false;

                $data = explode(':', $value);
                if(count($data) === 2){
                    // cookie data is well formatted
                    $characterAuth->getByForeignKey('selector', $data[0], ['limit' => 1]);

                    // validate "scope hash"
                    // -> either "normal" scopes OR "admin" scopes
                    // "expire data" and "validate token"
                    if( !$characterAuth->dry() ){
                        if(
                            strtotime($characterAuth->expires) >= $currentTime->getTimestamp() &&
                            hash_equals($characterAuth->token, hash('sha256', $data[1]))
                        ){
                            // cookie information is valid
                            // -> try to update character information from ESI
                            // e.g. Corp has changed, this also ensures valid "access_token"
                            /**
                             * @var $character Model\CharacterModel
                             */
                            $updateStatus = $characterAuth->characterId->updateFromESI();

                            if( empty($updateStatus) ){
                                // make sure character data is up2date!
                                // -> this is not the case if e.g. userCharacters was removed "ownerHash" changed...
                                $character = $characterAuth->rel('characterId');
                                $character->getById( $characterAuth->get('characterId', true) );

                                // check ESI scopes
                                $scopeHash = Util::getHashFromScopes($character->esiScopes);

                                if(
                                    $scopeHash === Util::getHashFromScopes(self::getScopesByAuthType()) ||
                                    $scopeHash === Util::getHashFromScopes(self::getScopesByAuthType('admin'))
                                ){
                                    // check if character still has user (is not the case of "ownerHash" changed
                                    // check if character is still authorized to log in (e.g. corp/ally or config has changed
                                    // -> do NOT remove cookie on failure. This can be a temporary problem (e.g. ESI is down,..)
                                    if( $character->hasUserCharacter() ){
                                        $authStatus = $character->isAuthorized();

                                        if(
                                            $authStatus == 'OK' ||
                                            !$checkAuthorization
                                        ){
                                            $character->virtual( 'authStatus', $authStatus);
                                        }

                                        $characters[$name] = $character;
                                    }
                                }else{
                                    // outdated/invalid ESI scopes
                                    $characterAuth->erase();
                                    $invalidCookie = true;
                                }
                            }else{
                                $invalidCookie = true;
                            }
                        }else{
                            // clear existing authentication data from DB
                            $characterAuth->erase();
                            $invalidCookie = true;
                        }
                    }else{
                        $invalidCookie = true;
                    }
                    $characterAuth->reset();
                }else{
                    $invalidCookie = true;
                }

                // remove invalid cookie
                if($invalidCookie){
                    $this->getF3()->clear('COOKIE.' . $name);
                }
            }
        }

        return $characters;
    }

    /**
     * get current character data from session
     * ->
     * @return array
     */
    public function getSessionCharacterData(){
        $data = [];

        if($user = $this->getUser()){
            $header                 = self::getRequestHeaders();
            $requestedCharacterId   = (int)$header['Pf-Character'];
            $browserTabId           = (string)$header['Pf-Tab-Id'];
            $tempCharacterData       = (array)$this->getF3()->get(Api\User::SESSION_KEY_TEMP_CHARACTER_DATA);

            if($this->getF3()->get('AJAX')){

                // _blank browser tab don´t have a $browserTabId jet..
                // first Ajax call from that new tab with empty $requestedCharacterId -> bind to that new tab
                if(
                    !empty($browserTabId) &&
                    $requestedCharacterId <= 0 &&
                    (int)$tempCharacterData['ID'] > 0 &&
                    empty($tempCharacterData['TAB_ID'])
                ){
                    $tempCharacterData['TAB_ID'] = $browserTabId;
                    // update tempCharacterData (SESSION)
                    $this->setTempCharacterData($tempCharacterData['ID'], $tempCharacterData['TAB_ID']);
                }

                if(
                    !empty($browserTabId) &&
                    !empty($tempCharacterData['TAB_ID']) &&
                    (int)$tempCharacterData['ID'] > 0 &&
                    $browserTabId === $tempCharacterData['TAB_ID']
                ){
                    $requestedCharacterId = (int)$tempCharacterData['ID'];
                }

            }elseif((int)$tempCharacterData['ID'] > 0){
                $requestedCharacterId = (int)$tempCharacterData['ID'];
            }

            $data = $user->getSessionCharacterData($requestedCharacterId);
        }

        return $data;
    }

    /**
     * get current character
     * @param int $ttl
     * @return Model\CharacterModel|null
     * @throws \Exception
     */
    public function getCharacter($ttl = 0){
        $character = null;
        $characterData = $this->getSessionCharacterData();

        if( !empty($characterData) ){
            /**
             * @var $characterModel Model\CharacterModel
             */
            $characterModel = Model\BasicModel::getNew('CharacterModel');
            $characterModel->getById( (int)$characterData['ID'], $ttl);

            if(
                !$characterModel->dry() &&
                $characterModel->hasUserCharacter()
            ){
                $character = &$characterModel;
            }
        }

        return $character;
    }

    /**
     * get current user
     * @param int $ttl
     * @return Model\UserModel|null
     */
    public function getUser($ttl = 0){
        $user = null;

        if($this->getF3()->exists(Api\User::SESSION_KEY_USER_ID, $userId)){
            /**
             * @var $userModel Model\UserModel
             */
            $userModel = Model\BasicModel::getNew('UserModel');
            $userModel->getById($userId, $ttl);

            if(
                !$userModel->dry() &&
                $userModel->hasUserCharacters()
            ){
                $user = &$userModel;
            }
        }

        return $user;
    }

    /**
     * set temp login character data (required during HTTP redirects on login)
     * @param int $characterId
     * @param string $browserTabId
     * @throws \Exception
     */
    protected function setTempCharacterData(int $characterId, string $browserTabId){
        if($characterId > 0){
            $tempCharacterData = [
                'ID'    => $characterId,
                'TAB_ID'    => trim($browserTabId)
            ];
            $this->getF3()->set(Api\User::SESSION_KEY_TEMP_CHARACTER_DATA, $tempCharacterData);
        }else{
            throw new \Exception( sprintf(self::ERROR_TEMP_CHARACTER_ID, $characterId) );
        }
    }

    /**
     * log out current character or all active characters (multiple browser tabs)
     * @param bool $all
     * @param bool $deleteSession
     * @param bool $deleteLog
     * @param bool $deleteCookie
     */
    protected function logoutCharacter(bool $all = false, bool $deleteSession = true, bool $deleteLog = true, bool $deleteCookie = false){
        $sessionCharacterData = (array)$this->getF3()->get(Api\User::SESSION_KEY_CHARACTERS);

        if($sessionCharacterData){
            $activeCharacterId = ($activeCharacter = $this->getCharacter()) ? $activeCharacter->_id : 0;
            /**
             * @var Model\CharacterModel $character
             */
            $character = Model\BasicModel::getNew('CharacterModel');
            $characterIds = [];
            foreach($sessionCharacterData as $characterData){
                if($characterData['ID'] === $activeCharacterId){
                    $characterIds[] = $activeCharacter->_id;
                    $activeCharacter->logout($deleteSession, $deleteLog, $deleteCookie);
                }elseif($all){
                    $character->getById($characterData['ID']);
                    $characterIds[] = $character->_id;
                    $character->logout($deleteSession, $deleteLog, $deleteCookie);
                }
                $character->reset();
            }

            if($characterIds){
                // broadcast logout information to webSocket server
                (new Socket( Config::getSocketUri() ))->sendData('characterLogout', $characterIds);
            }
        }
    }

    /**
     * get EVE server status from ESI
     * @param \Base $f3
     */
    public function getEveServerStatus(\Base $f3){
        $cacheKey = 'eve_server_status';
        if( !$f3->exists($cacheKey, $return) ){
            $return = (object) [];
            $return->error = [];
            $return->status = [
                'serverName' => strtoupper( self::getEnvironmentData('CCP_ESI_DATASOURCE') ),
                'serviceStatus' => 'offline'
            ];

            $response = $f3->ccpClient->getServerStatus();

            if( !empty($response) ){
                // calculate time diff since last server restart
                $timezone = $f3->get('getTimeZone')();
                $dateNow = new \DateTime('now', $timezone);
                $dateServerStart = new \DateTime($response['startTime']);
                $interval = $dateNow->diff($dateServerStart);
                $startTimestampFormat = $interval->format('%hh %im');
                if($interval->days > 0){
                    $startTimestampFormat = $interval->days . 'd ' . $startTimestampFormat;
                }

                $response['serverName'] = strtoupper( self::getEnvironmentData('CCP_ESI_DATASOURCE') );
                $response['serviceStatus'] = 'online';
                $response['startTime'] = $startTimestampFormat;
                $return->status = $response;

                $f3->set($cacheKey, $return, 60);
            }
        }

        echo json_encode($return);
    }

    /**
     * @param int $code
     * @param string $message
     * @param string $status
     * @param null $trace
     * @return \stdClass
     */
    protected function getErrorObject(int $code, string $message = '', string $status = '', $trace = null): \stdClass{
        $object = (object) [];
        $object->type = 'error';
        $object->code = $code;
        $object->status = empty($status) ? @constant('Base::HTTP_' . $code) : $status;
        if(!empty($message)){
            $object->message = $message;
        }
        if(!empty($trace)){
            $object->trace = $trace;
        }
        return $object;
    }

    /**
     * get a program URL by alias
     * -> if no $alias given -> get "default" route (index.php)
     * @param null $alias
     * @return bool|string
     */
    protected function getRouteUrl($alias = null){
        $url = false;

        if(!empty($alias)){
            // check given alias is a valid (registered) route
            if(array_key_exists($alias, $this->getF3()->get('ALIASES'))){
                $url = $this->getF3()->alias($alias);
            }
        }elseif($this->getF3()->get('ALIAS')){
            // get current URL
            $url = $this->getF3()->alias( $this->getF3()->get('ALIAS') );
        }else{
            // get main (index.php) URL
            $url = $this->getF3()->alias('login');
        }

        return $url;
    }

    /**
     * get a custom userAgent string for API calls
     * @return string
     */
    protected function getUserAgent(){
        $userAgent = '';
        $userAgent .= Config::getPathfinderData('name');
        $userAgent .=  ' - ' . Config::getPathfinderData('version');
        $userAgent .=  ' | ' . Config::getPathfinderData('contact');
        $userAgent .=  ' (' . $_SERVER['SERVER_NAME'] . ')';

        return $userAgent;
    }

    /**
     * onError() callback function
     * -> on AJAX request -> return JSON with error information
     * -> on HTTP request -> render error page
     * @param \Base $f3
     * @return bool
     */
    public function showError(\Base $f3){

        if(!headers_sent()){
            // collect error info -------------------------------------------------------------------------------------
            $error = $this->getErrorObject(
                $f3->get('ERROR.code'),
                $f3->get('ERROR.status'),
                $f3->get('ERROR.text'),
                $f3->get('DEBUG') === 3 ? $f3->get('ERROR.trace') : null
            );

            // check if error is a PDO Exception ----------------------------------------------------------------------
            if(strpos(strtolower( $f3->get('ERROR.text') ), 'duplicate') !== false){
                preg_match_all('/\'([^\']+)\'/', $f3->get('ERROR.text'), $matches, PREG_SET_ORDER);

                if(count($matches) === 2){
                    $error->field = $matches[1][1];
                    $error->message = 'Value "' . $matches[0][1] . '" already exists';
                }
            }

            // set response status ------------------------------------------------------------------------------------
            if(!empty($error->code)){
                $f3->status($error->code);
            }

            if($f3->get('AJAX')){
                $return = (object) [];
                $return->error[] = $error;
                echo json_encode($return);
            }else{
                $f3->set('tplPageTitle', 'ERROR - ' . $error->code);
                // set error data for template rendering
                $error->redirectUrl = $this->getRouteUrl();
                $f3->set('errorData', $error);

                if( preg_match('/^4[0-9]{2}$/', $error->code) ){
                    // 4xx error -> render error page
                    $f3->set('tplPageContent', Config::getPathfinderData('STATUS.4XX') );
                }elseif( preg_match('/^5[0-9]{2}$/', $error->code) ){
                    $f3->set('tplPageContent', Config::getPathfinderData('STATUS.5XX'));
                }
            }
        }

        return true;
    }

    /**
     * Callback for framework "unload"
     * -> this function is called on each request!
     * -> configured in config.ini
     * @param \Base $f3
     * @return bool
     */
    public function unload(\Base $f3){
        // track some 4xx Client side errors
        // 5xx errors are handled in "ONERROR" callback
        $status = http_response_code();
        if(!headers_sent() && $status >= 300){
            if($f3->get('AJAX')){
                $params = (array)$f3->get('POST');
                $return = (object) [];
                if((bool)$params['reroute']){
                    $return->reroute = rtrim(self::getEnvironmentData('URL'), '/') . $f3->alias('login');
                }else{
                    // no reroute -> errors can be shown
                    $return->error[] = $this->getErrorObject($status, Config::getMessageFromHTTPStatus($status));
                }

                echo json_encode($return);
            }
        }

        // store all user activities that are buffered for logging in this request
        // this should work even on non HTTP200 responses
        $this->logActivities();

        return true;
    }

    /**
     * store activity log data to DB
     */
    protected function logActivities(){
        LogController::instance()->logActivities();
        Monolog::instance()->log();
    }

    /**
     * get controller by class name
     * -> controller class is searched within all controller directories
     * @param $className
     * @return null|\Controller\
     * @throws \Exception
     */
    static function getController($className){
        $controller = null;
        // add subNamespaces for controller classes
        $subNamespaces = ['Api', 'Ccp'];

        for($i = 0; $i <= count($subNamespaces); $i++){
            $path = [__NAMESPACE__];
            $path[] = ( isset($subNamespaces[$i - 1]) ) ? $subNamespaces[$i - 1] : '';
            $path[] = $className;
            $classPath = implode('\\', array_filter($path));

            if(class_exists($classPath)){
                $controller = new $classPath();
                break;
            }
        }

        if( is_null($controller) ){
            throw new \Exception( sprintf('Controller class "%s" not found!', $className) );
        }

        return $controller;
    }


    /**
     * get scope array by a "role"
     * @param string $authType
     * @return array
     */
    static function getScopesByAuthType($authType = ''){
        $scopes = (array)self::getEnvironmentData('CCP_ESI_SCOPES');

        switch($authType){
            case 'admin':
                $scopesAdmin = (array)self::getEnvironmentData('CCP_ESI_SCOPES_ADMIN');
                $scopes = array_merge($scopes, $scopesAdmin);
                break;
        }
        sort($scopes);
        return $scopes;
    }

    /**
     * Helper function to return all headers because
     * getallheaders() is not available under nginx
     * @return array (string $key -> string $value)
     */
    static function getRequestHeaders(){
        $headers = [];

        $serverData = self::getServerData();

        if(
            function_exists('apache_request_headers') &&
            $serverData->type === 'apache'
        ){
            // Apache Webserver
            $headers = apache_request_headers();
        }else{
            // Other webserver, e.g. Nginx
            // Unfortunately this "fallback" does not work for me (Apache)
            // Therefore we can´t use this for all servers
            // https://github.com/exodus4d/pathfinder/issues/58
            foreach($_SERVER as $name => $value){
                if(substr($name, 0, 5) == 'HTTP_'){
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
        }

        return $headers;
    }

    /**
     * get some server information
     * @param int $ttl cache time (default: 1h)
     * @return \stdClass
     */
    static function getServerData($ttl = 3600){
        $f3 = \Base::instance();
        $cacheKey = 'PF_SERVER_INFO';

        if( !$f3->exists($cacheKey) ){
            $serverData = (object) [];
            $serverData->type = 'unknown';
            $serverData->version = 'unknown';
            $serverData->requiredVersion = 'unknown';
            $serverData->phpInterfaceType = php_sapi_name();

            if(strpos(strtolower($_SERVER['SERVER_SOFTWARE']), 'nginx' ) !== false){
                // Nginx server
                $serverSoftwareArgs = explode('/', strtolower( $_SERVER['SERVER_SOFTWARE']) );
                $serverData->type = reset($serverSoftwareArgs);
                $serverData->version = end($serverSoftwareArgs);
                $serverData->requiredVersion = $f3->get('REQUIREMENTS.SERVER.NGINX.VERSION');
            }elseif(strpos(strtolower($_SERVER['SERVER_SOFTWARE']), 'apache' ) !== false){
                // Apache server
                $serverData->type = 'apache';
                $serverData->requiredVersion = $f3->get('REQUIREMENTS.SERVER.APACHE.VERSION');

                // try to get the apache version...
                if(function_exists('apache_get_version')){
                    // function does not exists if PHP is running as CGI/FPM module!
                    $matches = preg_split('/[\s,\/ ]+/', strtolower( apache_get_version() ) );
                    if(count($matches) > 1){
                        $serverData->version = $matches[1];
                    }
                }
            }

            // cache data for one day
            $f3->set($cacheKey, $serverData, $ttl);
        }

        return $f3->get($cacheKey);
    }

    /**
     * get the current registration status
     * 0=registration stop |1=new registration allowed
     * @return int
     */
    static function getRegistrationStatus(){
        return (int)Config::getPathfinderData('registration.status');
    }

    /**
     * get a Logger object by Hive key
     * -> set in pathfinder.ini
     * @param string $type
     * @return \Log|null
     */
    static function getLogger($type){
        return LogController::getLogger($type);
    }

    /**
     * removes illegal characters from a Hive-key that are not allowed
     * @param $key
     * @return string
     */
    static function formatHiveKey($key){
        $illegalCharacters = ['-', ' '];
        return strtolower( str_replace($illegalCharacters, '', $key) );
    }

    /**
     * get environment specific configuration data
     * @param string $key
     * @return string|array|null
     */
    static function getEnvironmentData($key){
        return Config::getEnvironmentData($key);
    }


    /**
     * health check for ICP socket -> ping request
     * @param $ttl
     * @param $load
     */
    static function checkTcpSocket($ttl, $load){
        (new Socket( Config::getSocketUri(), $ttl ))->sendData('healthCheck', $load);
    }

} 