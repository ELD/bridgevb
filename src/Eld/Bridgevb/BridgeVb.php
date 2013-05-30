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

	public function isLoggedIn() 
	{
		return ($this->userInfo->userid ? true : false);
	}

	public function isAdmin() {}

	public function getLogoutHash() 
	{
		return time() . '-' . sha1(time() . sha1($this->userInfo->userid . sha1($this->userInfo->salt) . sha1($this->cookieHash)));
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
		$res = DB::connection($this->connection)->table($this->databasePrefix . 'user')->where('username', '=', $username)
			->where('password', '=', 'md5(concat(md5(' . $password . '), salt))')->take(1)->get();
		if($res)
			return intval($res[0]->userid);

		return false;
	}

	protected function createCookieUser($userid, $passwordd)
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
		setcookie($this->cookie_prefix . 'sessionhash', '', time() - 3600);
		setcookie($this->cookie_prefix . 'userid', '', time() - 3600);
		setcookie($this->cookie_prefix . 'password', '', time() - 3600);

		DB::connection($this->connection)->table($this->databasePrefix . 'session')->where('sessionhash', '=', $this->userInfo->sessionHash)->delete();
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