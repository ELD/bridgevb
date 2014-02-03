<?php namespace Eld\BridgeVb;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;

class BridgeVb
{
    /**
     * The connection string
     * 
     * @var string
     */
    protected $connection;
    /**
     * The cookie hash unique to the vBulletin forum
     * 
     * @var string
     */
    protected $cookieHash;
    /**
     * The prefix on all cookies set by vBulletin
     * 
     * @var string
     */
    protected $cookiePrefix;
    /**
     * The length of time before a cookie times out
     * 
     * @var integer
     */
    protected $cookieTimeout;
    /**
     * The string containing the database tables prefixes
     * 
     * @var string
     */
    protected $databasePrefix;
    /**
     * The default user in case authentication fail
     * 
     * @var array
     */
    protected $defaultUser = array(
        'userid' => 0,
        'username' => 'unregistered',
        'usergroupid' => 3,
        'membergroupids' => '',
        'sessionhash' => '',
        'salt' => ''
    );
    /**
     * The forum URL
     * 
     * @var string
     */
    protected $forumPath;
    /**
     * An array of the columns to be fetched from the user table containing user information
     * 
     * @var array
     */
    protected $userColumns;
    /**
     * An array of all known usergroups
     * 
     * @var array
     */
    protected $userGroups;
    /**
     * An object containing all the relevant information about a user
     * 
     * @var stdClass
     */
    protected $userInfo;

    /**
     * BridgeVb's constructor. It initializes all values based on the config file and sets the default user and
     * authenticates the session.
     *  
     * @return void
     */
    public function __construct()
    {
        $this->connection = Config::get('bridgevb::connection');
        $this->cookieHash = Config::get('bridgevb::cookie_hash');
        $this->cookiePrefix = Config::get('bridgevb::cookie_prefix');
        $this->cookieTimeout = DB::connection($this->connection)
            ->table('setting')->where('varname', '=', 'cookietimeout')->take(1)
            ->get();
        $this->cookieTimeout = $this->cookieTimeout[0]->value;
        $this->databasePrefix = Config::get('bridgevb::db_prefix');
        $this->forumPath = Config::get('bridgevb::forum_path');
        $this->userColumns = Config::get('bridgevb::user_columns');
        $this->userGroups = Config::get('bridgevb::user_groups');

        $this->setUserInfo($this->defaultUser);
        $this->authenticateSession();
    }

    /**
     * Checks if the user is in the user group passed into the function
     * 
     * @param  string  $group The usergroup that's being checked
     * @return boolean Returns true if the user is in the group, returns false if they're not
     */
    public function is($group)
    {
        if ($this->userInfo->userid) {
            if (array_key_exists($group, $this->userGroups)) {
                $userInfoGroups = explode(',', $this->userInfo->membergroupids);
                if (in_array($this->userGroups[$group][0], $userInfoGroups)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Attempts login based on credentials passed in
     * 
     * @param  array   $credentials An array containing all the necessary login information 
     * @return boolean Returns true if user is now logged in, returns false if attempt fails
     */
    public function attempt(array $credentials)
    {
        if (array_key_exists('username', $credentials) && array_key_exists('password', $credentials)
            && array_key_exists('remember_me', $credentials)) {
            $credentials = (object)$credentials;
            $user = $this->isValidLogin($credentials->username, $credentials->password);
            if ($user) {
                if ($credentials->remember_me) {
                    $this->createCookieUser($user->userid, $user->password);
                }
                $this->createSession($user->userid, $credentials->remember_me);
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the user is logged in
     * 
     * @return boolean Returns true if the user is logged in, returns false if they're not.
     */
    public function isLoggedIn()
    {
        return ($this->userInfo->userid ? true : false);
    }

    /**
     * Returns an object containing the user's information
     * 
     * @return stdClass An object containing all user information from the database
     */
    public function getUserInfo()
    {
        return $this->userInfo;
    }

    /**
     * Gets the particular piece of user information passed in
     * 
     * @param  string $val The attribute of the user you want to grab 
     * @return string The piece of username the $val variable corresponds to
     */
    public function get($val)
    {
        return $this->userInfo->{$val};
    }

    /**
     * Returns the logout hash necessary to link to the vBulletin logout link
     * 
     * @return string The logout hash necessary in the login.php?do=logout&logouthash= link
     */
    public function getLogoutHash()
    {
        return time() . '-' . sha1(
            time() . sha1($this->userInfo->userid . sha1($this->userInfo->salt) . sha1($this->cookieHash))
        );
    }

    /**
     * Manual logout function that destroys the session in the vBulletin database and destroys all cookies
     * 
     * @return void
     */
    public function logout()
    {
        $this->deleteSession();
    }

    /**
     * Attempts to authenticate the user based on cookies and sessions in the database
     * 
     * @return boolean Returns a non-zero value if the user is logged in
     */
    protected function authenticateSession()
    {
        $userid =
            (isset($_COOKIE[$this->cookiePrefix . 'userid']) ? $_COOKIE[$this->cookiePrefix . 'userid'] : false);
        $password =
            (isset($_COOKIE[$this->cookiePrefix . 'password']) ? $_COOKIE[$this->cookiePrefix . 'password'] : false);

        $sessionHash = (isset($_COOKIE[$this->cookiePrefix . 'sessionhash']) ?
            $_COOKIE[$this->cookiePrefix . 'sessionhash'] : false);

        if ($userid && $password) {
            $user = $this->isValidCookieUser($userid, $password);

            if ($user) {
                $sessionHash = $this->updateOrCreateSession($userid);
                $userinfo = DB::connection($this->connection)->table($this->databasePrefix . 'user')
                    ->where('userid', '=', $userid)->take(1)->get($this->userColumns);
                $userinfo = $userinfo[0];
                $this->setUserInfo($userinfo);
                return true;
            } else {
                return false;
            }
        } elseif ($sessionHash) {

            $session = DB::connection($this->connection)->table($this->databasePrefix . 'session')
                ->where('sessionhash', '=', $sessionHash)->where('idhash', '=', $this->fetchIdHash())->get();

            if ($session) {
                $session = $session[0];

                if ($session && ($session->host == Request::server('REMOTE_ADDR'))) {
                    $userinfo = DB::connection($this->connection)->table($this->databasePrefix . 'user')
                        ->where('userid', '=', $session->userid)->take(1)->get($this->userColumns);

                    if (!$userinfo) {
                        return false;
                    }

                    $userinfo = $userinfo[0];

                    $this->setUserInfo($userinfo);
                    $this->updateOrCreateSession($userinfo->userid);

                    return true;
                }
            } else {
                return false;
            }            
        } else {
            return false;
        }

        return false;
    }

    /**
     * Checks if the user's cookies are valid
     * 
     * @param  string  $userid   The userid contained within the cookie
     * @param  string  $password The password hash stored within the cookie 
     * @return boolean           Returns a non-zero avlue if the user's cookies are valid
     */
    protected function isValidCookieUser($userid, $password)
    {
        $dbPass = DB::connection($this->connection)->table($this->databasePrefix . 'user')
            ->where('userid', '=', $userid)->take(1)->get(array('password'));
        if ($dbPass) {
            $dbPass = $dbPass[0];
        }

        if ($password == md5($dbPass->password . $this->cookieHash)) {
            return intval($userid);
        }

        return false;
    }

    /**
     * Checks if the username and password is valid
     * 
     * @param  string  $username The username passed in by input
     * @param  string  $password The password from input 
     * @return boolean           Returns a non-zero value if the user is a valid login
     */
    protected function isValidLogin($username, $password)
    {
        $saltAndPassword = DB::connection($this->connection)->table($this->databasePrefix . 'user')
            ->where('username', '=', $username)->get($this->userColumns);
        if ($saltAndPassword) {
            $saltAndPassword = $saltAndPassword[0];
            if ($saltAndPassword->password == (md5(md5($password) . $saltAndPassword->salt))) {
                return $saltAndPassword;
            }
        }

        return false;
    }

    /**
     * Creates the corresponding cookies if a user checks the 'remember me' box
     * 
     * @param  strign $userid   The user's id
     * @param  string $password The user's password 
     * @return void
     */
    protected function createCookieUser($userid, $password)
    {
        setcookie($this->cookiePrefix . 'userid', $userid, time() + 31536000, '/');
        setcookie($this->cookiePrefix . 'password', md5($password . $this->cookieHash), time() + 31536000, '/');
    }

    /**
     * Creates a new session in the database
     * 
     * @param  string  $userid      The user's id
     * @param  boolean $rememberMe  Default value of true, but if false sets a temporary cookie 
     * @return boolean              Returns a non-zero value
     */
    protected function createSession($userid, $rememberMe = true)
    {
        $hash = md5(microtime() . $userid . Request::server('REMOTE_ADDR'));

        $timeout = time() + $this->cookieTimeout;

        if ($rememberMe) {
            setcookie($this->cookiePrefix . 'sessionhash', $hash, $timeout, '/');
        } elseif (!$rememberMe) {
            setcookie($this->cookiePrefix . 'sessionhash', $hash, 0, '/');
        }

        $session = array (
            'userid' => $userid,
            'sessionhash' => $hash,
            'host' => Request::server('REMOTE_ADDR'),
            'idhash' => $this->fetchIdHash(),
            'lastactivity' => time(),
            'location' => Request::server('REQUEST_URI'),
            'useragent' => substr(Request::server('HTTP_USER_AGENT'), 0, 100),
            'loggedin' => 1,
        );

        DB::connection($this->connection)->table($this->databasePrefix . 'session')
            ->where('host', '=', Request::server('REMOTE_ADDR'))
            ->where('useragent', '=', substr(Request::server('HTTP_USER_AGENT'), 0, 100))->delete();

        DB::connection($this->connection)->table($this->databasePrefix . 'session')->insert($session);

        return $hash;
    }

    /**
     * Updates or creates a new session based on existing rows in the database
     * 
     * @param  string  $userid The user's id 
     * @return boolean         Returns a non-zero value
     */
    protected function updateOrCreateSession($userid)
    {
        $sessionHash = (isset($_COOKIE[$this->cookiePrefix . 'sessionhash'])) ? 
            $_COOKIE[$this->cookiePrefix . 'sessionhash'] : "";

        $activityAndHash = DB::connection($this->connection)->table($this->databasePrefix . 'session')
            ->where('userid', '=', $userid)->where('idhash', '=', $this->fetchIdHash())
            ->where('sessionhash', '=', $sessionHash)
            ->get(array('sessionhash', 'lastactivity'));

        if ($activityAndHash) {
            $activityAndHash = $activityAndHash[0];
            if ((time() - $activityAndHash->lastactivity) < $this->cookieTimeout) {

                $updatedSession = array(
                    'userid' => $userid,
                    'host' => Request::server('REMOTE_ADDR'),
                    'lastactivity' => time(),
                    'location' => Request::server('REQUEST_URI'),
                );

                DB::connection($this->connection)->table($this->databasePrefix . 'session')
                    ->where('userid', '=', $userid)
                    ->where('useragent', '=', substr(Request::server('HTTP_USER_AGENT'), 0, 100))
                    ->where('sessionhash','=', $_COOKIE[$this->cookiePrefix . 'sessionhash'])
                    ->update($updatedSession);

                return $activityAndHash->sessionhash;
            } else {
                var_dump('refreshing session');
                $newSessionHash = md5(microtime() . $userid . Request::server('REMOTE_ADDR'));
                $timeout = time() + $this->cookieTimeout;
                setcookie($this->cookiePrefix . 'sessionhash', $newSessionHash, $timeout, '/');

                $newSession = array (
                    'userid' => $userid,
                    'sessionhash' => $newSessionHash,
                    'host' => Request::server('REMOTE_ADDR'),
                    'idhash' => $this->fetchIdHash(),
                    'lastactivity' => time(),
                    'location' => Request::server('REQUEST_URI'),
                    'useragent' => substr(Request::server('HTTP_USER_AGENT'), 0, 100),
                    'loggedin' => 1,
                );

                DB::connection($this->connection)->table($this->databasePrefix . 'session')->insert($newSession);

                return $newSessionHash;
            }
        } else {
            return $this->createSession($userid);
        }

    }

    /**
     * Deletes the session in the database and the cookies in the browser to effectively log a user out
     * 
     * @return void
     */
    protected function deleteSession()
    {
        $sessionHash = $_COOKIE[$this->cookiePrefix . 'sessionhash'];
        setcookie($this->cookiePrefix . 'sessionhash', '', time() - 3600, '/');

        setcookie($this->cookiePrefix . 'userid', '', time() - 3600, '/');
        setcookie($this->cookiePrefix . 'password', '', time() - 3600, '/');

        DB::connection($this->connection)->table($this->databasePrefix . 'session')
            ->where('userid', '=', $this->userInfo->userid)
            ->where('useragent', '=', substr(Request::server('HTTP_USER_AGENT'), 0, 100))->delete();

        $hash = md5(microtime() . 0 . Request::server('REMOTE_ADDR'));
        $anonymousSession = array(
            'userid' => 0,
            'sessionhash' => $hash,
            'host' => Request::server('REMOTE_ADDR'),
            'idhash' => $this->fetchIdHash(),
            'lastactivity' => time(),
            'location' => Request::server('REQUEST_URI'),
            'useragent' => substr(Request::server('HTTP_USER_AGENT'), 0, 100),
            'loggedin' => 0,
        );

        DB::connection($this->connection)->table($this->databasePrefix . 'session')->insert($anonymousSession);
    }

    /**
     * Fetches a given user's id hash
     * 
     * @return string The unique ID hash to each client
     */
    protected function fetchIdHash()
    {
        return md5(Request::server('HTTP_USER_AGENT') . $this->fetchIp());
    }

    /**
     * Fetches the shortened IP used in hashing
     * 
     * @return string The shortened IP address
     */
    protected function fetchIp()
    {
        $ip = Request::server('REMOTE_ADDR');
        return implode('.', array_slice(explode('.', $ip), 0, 4 -1));
    }

    /**
     * Sets the userInfo object
     * 
     * @param  mixed $userinfo Existing userinfo passed in and set to the userInfo attribute 
     * @return void
     */
    protected function setUserInfo($userinfo)
    {
        $this->userInfo = (object) $userinfo;
    }

    protected function dummy()
    {
        return 'slimed';
    }
}
