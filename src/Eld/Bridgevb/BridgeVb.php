<?php namespace Eld\BridgeVb;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Cookie;

class BridgeVb {

	protected $connection;
	protected $cookieHash;
	protected $cookiePrefix;
	protected $sessionHash;
	protected $idHash;
	protected $userId;
	protected $lastActive;
	protected $dbPass;

	public function __construct()
	{
		$this->connection = Config::get('bridgevb::connection');
		$this->cookieHash = Config::get('bridgevb::cookiehash');
		$this->cookiePrefix = Config::get('bridgevb::cookieprefix');
	}

	public function test()
	{
		dd($this->isLoggedIn());
	}

	public function isLoggedIn()
	{
		if($_COOKIE[$this->cookiePrefix . 'userid'] && $_COOKIE[$this->cookiePrefix . 'password'])
		{
			if($this->getCookie($_COOKIE[$this->cookiePrefix . 'userid'], $_COOKIE[$this->cookiePrefix . 'password']))
			{
				return $_COOKIE[$this->cookiePrefix . 'userid'];
			}
		}
		if($_COOKIE[$this->cookiePrefix . 'sessionhash'])
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