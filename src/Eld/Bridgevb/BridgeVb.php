<?php namespace Eld\BridgeVb;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Cookie;

class BridgeVb {

	// Connection string referring to the database connection specified in database.php
	protected $connection;
	// Cookie hash unique to each vBulletin installation
	protected $cookieHash;
	// The prefix of the vBulletin cookies
	protected $cookiePrefix;
	// The hash that represents the session inside the database
	protected $sessionHash;
	// The ID hash
	protected $idHash;
	// The user's ID
	protected $userId;
	// The last activity of the member
	protected $lastActive;
	// The password hash contained in the database
	protected $dbPass;

	public function __construct()
	{
		$this->connection = Config::get('bridgevb::connection');
		$this->cookieHash = Config::get('bridgevb::cookie_hash');
		$this->cookiePrefix = Config::get('bridgevb::cookie_prefix');
	}

	public function test()
	{
		// dd(Cookie::get($this->cookiePrefix . 'userid'));
		$this->isLoggedIn();
	}

	/**
	 * Authenticates the vBulletin user using cookies and sessions. Returns false if the user isn't logged in.
	 * @return boolean Returns whether the user is logged in or not.
	 */
	public function isLoggedIn()
	{
		if(isset($_COOKIE[$this->cookiePrefix . 'userid']) && isset($_COOKIE[$this->cookiePrefix . 'password']))
		{
			if($this->getCookie($_COOKIE[$this->cookiePrefix . 'userid'], $_COOKIE[$this->cookiePrefix . 'password']))
			{
				return $_COOKIE[$this->cookiePrefix . 'userid'];
			}
		}
		if(isset($_COOKIE[$this->cookiePrefix . 'sessionhash']))
		{
			if($this->getSession($_COOKIE[$this->cookiePrefix . 'sessionhash']))
			{
				return $this->getSession($_COOKIE[$this->cookiePrefix . 'sessionhash']);
			}
		}
		return false;
	}

	public function getUserInfo($id)
	{
		$result = DB::connection($this->connection)->table('user')->where('userid', '=', $id)->take(1)->get();
		return ($result ? $result[0] : false);
	}

	private function getSession($hash)
	{
		$ip = explode('.', Request::server('REMOTE_ADDR'));
		$ip = implode('.', array_splice($ip, 0, 4 - 1));
		
		$newIdHash = md5(Request::server('HTTP_USER_AGENT') . $ip);

		$result = DB::connection($this->connection)->table('session')->where('sessionhash', '=', $hash)
						->take(1)->get();

		if ($result)
		{
			$this->sessionHash = $result[0]->sessionhash;
			$this->idHash = $result[0]->idhash;
			$this->userId = $result[0]->userid;
			$this->lastActive = $result[0]->lastactivity;

			return ($this->idHash == $newIdHash && (time() - $this->lastActive) < 900 ? $this->userId : false);
		}

		return false;
	}

	private function getCookie($id, $pass)
	{
		$result = DB::connection($this->connection)->table('user')->where('userid', '=', $id)->take(1)->get();
		if($result)
		{
			$result = DB::connection($this->connection)->table('user')->where('userid', '=', $id)->take(1)->get();

			if ($result)
			{
				$dbpass = $result[0]->password;
				
				if (md5($dbpass . $this->cookieHash) == $pass)
				{
					return $id;
				}
			}
			return false;
		}
	}

}