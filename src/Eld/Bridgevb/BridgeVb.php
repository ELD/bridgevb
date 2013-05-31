<?php namespace Eld\BridgeVb;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Cookie;

class BridgeVb {

	// The database connection from the config file
	protected $connection;
	// Cookie hash
	protected $cookieHash;
	// Cookie prefix
	protected $cookiePrefix;
	// Cookie timeout time
	protected $cookieTimeout;
	// Database table prefix
	protected $databasePrefix;
	// Default user info if none is found
	protected $defaultUser = array(
		'userid' => 0,
		'username' => 'unregistered',
		'usergroupid' => 3,
		'membergroupids' => '',
		'sessionhash' => '',
		'salt' => ''
	);
	// Forum URL
	protected $forumPath;
	// The columns to select when fetching user info
	protected $userColumns;
	// Array of user groups
	protected $userGroups;
	// User info array
	protected $userInfo;

	public function __construct()
	{
		$this->connection = Config::get('bridgevb::connection');
		$this->cookieHash = Config::get('bridgevb::cookie_hash');
		$this->cookiePrefix = Config::get('bridgevb::cookie_prefix');
		$this->cookieTimeout = DB::connection($this->connection)
			->table('setting')->where('varname', '=', 'cookietimeout')->take(1)
			->get()[0]->value;
		$this->databasePrefix = Config::get('bridgevb::db_prefix');
		$this->forumPath = Config::get('bridgevb::forum_path');
		$this->userColumns = Config::get('bridgevb::user_columns');
		$this->userGroups = Config::get('bridgevb::user_groups');

		$this->setUserInfo($this->defaultUser);
		$this->authenticateSession();
	}

	public function is($group) {}

	public function attempt(array $credentials)
	{
		if(array_key_exists('username', $credentials) && array_key_exists('password', $credentials))
		{
			$credentials = (object)$credentials;
			$user = $this->isValidLogin($credentials->username, $credentials->password);
			if($user)
			{
				$this->createCookieUser($user->userid, $user->password);
				$this->createSession($user->userid);
				return true;
			}
		}

		return false;
	}

	public function isLoggedIn() 
	{
		return ($this->userInfo->userid ? true : false);
	}

	public function isAdmin() 
	{
		// $groups = explode(',', $this->userInfo->membergroupids);
		// if(in_array($this->userGroups, haystack))
	}

	public function getUserInfo()
	{
		return $this->userInfo;
	}

	public function getLogoutHash() 
	{
		return time() . '-' . sha1(time() . sha1($this->userInfo->userid . sha1($this->userInfo->salt) . sha1($this->cookieHash)));
	}

	public function logout()
	{
		$this->deleteSession();
	}

	protected function authenticateSession()
	{
		$userid = (isset($_COOKIE[$this->cookiePrefix . 'userid']) ? $_COOKIE[$this->cookiePrefix . 'userid'] : false);
		$password = (isset($_COOKIE[$this->cookiePrefix . 'password']) ? $_COOKIE[$this->cookiePrefix . 'password'] : false);

		$sessionHash = (isset($_COOKIE[$this->cookiePrefix . 'sessionhash']) ? $_COOKIE[$this->cookiePrefix . 'sessionhash'] : false);

		if($userid && $password)
		{
			$user = $this->isValidCookieUser($userid, $password);

			if($user)
			{
				// changed from createSession to updateSession
				$sessionHash = $this->updateOrCreateSession($userid);
			}
			else
			{
				return false;
			}
		}

		if($sessionHash)
		{
			$session = DB::connection($this->connection)->table($this->databasePrefix . 'session')->where('sessionhash', '=', $sessionHash)
				->where('idhash', '=', $this->fetchIdHash())->where('lastactivity', '>', $this->cookieTimeout)->get();

			if($session)
				$session = $session[0];
			else
				return false;

			if($session && ($session->host == Request::server('REMOTE_ADDR')))
			{
				$userinfo = DB::connection($this->connection)->table($this->databasePrefix . 'user')->where('userid', '=', $session->userid)->take(1)->get();

				if(!$userinfo)
				{
					return false;
				}

				$userinfo = $userinfo[0];

				$userinfo->sessionhash = $session->sessionhash;

				$this->setUserInfo($userinfo);

				$updateSession = array(
					'lastactivity' => time(),
					'location' => Request::server('REQUEST_URI'),
				);

				DB::connection($this->connection)->table($this->databasePrefix . 'session')->where('sessionhash', '=', $userinfo->sessionhash)->update($updateSession);

				return true;
			}
		}

		return false;
	}

	protected function isValidCookieUser($userid, $password)
	{
		$dbPass = DB::connection($this->connection)->table($this->databasePrefix . 'user')->where('userid', '=', $userid)->take(1)->get(array('password'));
		if($dbPass)
			$dbPass = $dbPass[0];

		if($password == md5($dbPass->password . $this->cookieHash))
			return intval($userid);
		
		return false;
	}

	protected function isValidLogin($username, $password)
	{
		$saltAndPassword = DB::connection($this->connection)->table($this->databasePrefix . 'user')->where('username', '=', $username)->get(array('salt', 'password', 'userid'));
		if($saltAndPassword)
		{
			$saltAndPassword = $saltAndPassword[0];
			if($saltAndPassword->password == (md5(md5($password) . $saltAndPassword->salt)))
			{
				return $saltAndPassword;
			}
		}

		return false;
	}

	protected function createCookieUser($userid, $password)
	{
		setcookie($this->cookiePrefix . 'userid', $userid, time() + 31536000, '/');
		setcookie($this->cookiePrefix . 'password', md5($password . $this->cookieHash), time() + 31536000, '/');
	}

	protected function createSession($userid)
	{
		$hash = md5(microtime() . $userid . Request::server('REMOTE_ADDR'));

		$timeout = time() + $this->cookieTimeout;

		setcookie($this->cookiePrefix . 'sessionhash', $hash, $timeout, '/');

		$session = array (
			'userid' => $userid,
			'sessionhash' => $hash,
			'host' => Request::server('REMOTE_ADDR'),
			'idhash' => $this->fetchIdHash(),
			'lastactivity' => time(),
			'location' => Request::server('REQUEST_URI'),
			'useragent' => Request::server('HTTP_USER_AGENT'),
			'loggedin' => 1,
		);

		DB::connection($this->connection)->table($this->databasePrefix . 'session')->where('host', '=', Request::server('REMOTE_ADDR'))->delete();
		DB::connection($this->connection)->table($this->databasePrefix . 'session')->insert($session);

		return $hash;
	}

	protected function updateOrCreateSession($userid)
	{
		$activityAndHash = DB::connection($this->connection)->table($this->databasePrefix . 'session')->where('userid', '=', $userid)->get(array('sessionhash', 'lastactivity'));
		if($activityAndHash)
		{
			$activityAndHash = $activityAndHash[0];
			if ((time() - $activityAndHash->lastactivity) < $this->cookieTimeout)
			{
				$updatedSession = array(
					'userid' => $userid,
					'host' => Request::server('REMOTE_ADDR'),
					'lastactivity' => time(),
					'location' => Request::server('REQUEST_URI'),
				);

				DB::connection($this->connection)->table($this->databasePrefix . 'session')->where('userid', '=', $userid)->update($updatedSession);
				return $activityAndHash->sessionhash;
			}
			else
			{
				// DB::connection($this->connection)->table($this->databasePrefix . 'session')->where('userid', '=', $userid)->delete();
				// return $this->createSession($userid);
				$newSessionHash = md5(microtime() . $userid . Request::server('REMOTE_ADDR'));
				$timeout = time() + $this->cookieTimeout;
				setcookie($this->cookiePrefix . 'sessionhash', $newSessionHash, $timeout, '/');

				$updatedSession = array(
					'sessionhash' => $newSessionHash,
					'userid' => $userid,
					'host' => Request::server('REMOTE_ADDR'),
					'lastactivity' => time(),
					'location' => Request::server('REQUEST_URI'),
				);

				DB::connection($this->connection)->table($this->databasePrefix . 'session')->where('userid', '=', $userid)->update($updatedSession);

				return $newSessionHash;
			}
		}
		else
		{
			return $this->createSession($userid);
		}

	}

	protected function deleteSession()
	{
		$sessionHash = $_COOKIE[$this->cookiePrefix . 'sessionhash'];
		setcookie($this->cookiePrefix . 'sessionhash', '', time() - 3600);
		setcookie($this->cookiePrefix . 'userid', '', time() - 3600);
		setcookie($this->cookiePrefix . 'password', '', time() - 3600);

		DB::connection($this->connection)->table($this->databasePrefix . 'session')->where('sessionhash', '=', $this->userInfo->sessionhash)->delete();
		$hash = md5(microtime() . 0 . Request::server('REMOTE_ADDR'));
		$anonymousSession = array(
			'userid' => 0,
			'sessionhash' => $hash,
			'host' => Request::server('REMOTE_ADDR'),
			'idhash' => $this->fetchIdHash(),
			'lastactivity' => time(),
			'location' => Request::server('REQUEST_URI'),
			'useragent' => Request::server('HTTP_USER_AGENT'),
			'loggedin' => 0,
		);
		DB::connection($this->connection)->table($this->databasePrefix . 'session')->insert($anonymousSession);
	}

	protected function fetchIdHash()
	{
		return md5(Request::server('HTTP_USER_AGENT') . $this->fetchIp());
	}

	protected function fetchIp()
	{
		$ip = Request::server('REMOTE_ADDR');
		return implode('.', array_slice(explode('.', $ip), 0, 4 -1));
	}

	protected function setUserInfo($userinfo)
	{
		$this->userInfo = (object) $userinfo;
	}

}