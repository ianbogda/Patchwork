<?php /*********************************************************************
 *
 *   Copyright : (C) 2006 Nicolas Grekas. All rights reserved.
 *   Email     : nicolas.grekas+patchwork@espci.org
 *   License   : http://www.gnu.org/licenses/gpl.txt GNU/GPL, see COPYING
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/


class
{
	/* Public properties */

	static $IPlevel = 2;

	static $maxIdleTime = 0;
	static $maxLifeTime = 43200;

	static $gcProbabilityNumerator = 1;
	static $gcProbabilityDenominator = 100;

	static $cookiePath = '/';
	static $cookieDomain = '';

	static $authVars = array();
	static $groupVars = array();


	/* Protected properties */

	protected static $DATA;
	protected static $adapter = false;

	protected static $SID = '';
	protected static $lastseen = '';
	protected static $birthtime = '';
	protected static $sslid = '';
	protected static $isIdled = false;


	/* Public methods */

	static function getSID()      {patchwork::setGroup('private'); return self::$SID;}
	static function getLastseen() {patchwork::setGroup('private'); return self::$lastseen;}

	static function get($name)
	{
		$value = isset(self::$DATA[$name]) ? self::$DATA[$name] : '';
		patchwork::setGroup(isset(self::$groupVars[$name]) ? 'session/' . $name . '/' . $value : 'private');
		return $value;
	}

	static function set($name, $value = '')
	{
		if (is_array($name) || is_object($name))
		{
			$regenerateId = false;

			foreach ($name as $k => &$value)
			{
				self::$DATA[$k] =& $value;
				$regenerateId || $regenerateId = isset(self::$authVars[$k]);
			}

			$regenerateId && self::regenerateId();
		}
		else
		{
			if ('' === $value) unset(self::$DATA[$name]);
			else self::$DATA[$name] =& $value;

			isset(self::$authVars[$name]) && self::regenerateId();
		}
	}

	static function bind($name, &$value)
	{
		$value = self::get($name);
		patchwork::setGroup(isset(self::$groupVars[$name]) ? 'session/' . $name . '/' . $value : 'private');
		self::set(array($name => &$value));
	}

	static function flash($name, $value = '')
	{
		$a = self::get($name);
		self::set($name, $value);
		return $a;
	}

	static function getAll()
	{
		$a = array();
		foreach (self::$DATA as $k => &$v)
		{
			patchwork::setGroup(isset(self::$groupVars[$k]) ? 'session/' . $k . '/' . $v : 'private');
			$a[$k] =& $v;
		}

		return $a;
	}

	static function regenerateId($initSession = false, $restartNew = true)
	{
		if (self::$adapter)
		{
			self::$adapter->reset();
			self::$adapter = false;
		}

		if ($initSession) self::$DATA = array();


		// Generate a new antiCSRF token

		$sid = isset($_COOKIE['T$']) && '1' == substr($_COOKIE['T$'], 0, 1) ? '1' : '2';
		$GLOBALS['patchwork_token'] = $sid . patchwork::uniqid();

		setcookie('T$', $GLOBALS['patchwork_token'], 0, self::$cookiePath, self::$cookieDomain);


		if (!$initSession || $restartNew)
		{
			$sid = patchwork::uniqid();
			self::$sslid = (isset($_SERVER['HTTPS']) ? '' : '-') . patchwork::uniqid();
			self::setSID($sid);

			self::$adapter = new SESSION(self::$SID);

			self::$lastseen = $_SERVER['REQUEST_TIME'];
			self::$birthtime = $_SERVER['REQUEST_TIME'];
		}
		else self::$sslid = $sid = '';

		setcookie('SID',         $sid, 0, self::$cookiePath, self::$cookieDomain, false, true);
		setcookie('SSL', self::$sslid, 0, self::$cookiePath, self::$cookieDomain, true , true);

		// 304 Not Modified response code does not allow Set-Cookie headers,
		// so we remove any header that could trigger a 304
		unset($_SERVER['HTTP_IF_NONE_MATCH']);
		unset($_SERVER['HTTP_IF_MODIFIED_SINCE']);
	}

	static function destroy()
	{
		self::regenerateId(true, false);
	}

	static function close()
	{
		self::$adapter = false;
	}


	/* Internal methods */

	static function __static_construct()
	{
		global $CONFIG;

		isset($CONFIG['session.auth_vars'])     && self::$authVars     = array_merge(self::$authVars , $CONFIG['session.auth_vars']);
		isset($CONFIG['session.group_vars'])    && self::$groupVars    = array_merge(self::$groupVars, $CONFIG['session.group_vars']);
		isset($CONFIG['session.cookie_path'])   && self::$cookiePath   = $CONFIG['session.cookie_path'];
		isset($CONFIG['session.cookie_domain']) && self::$cookieDomain = $CONFIG['session.cookie_domain'];

		self::$authVars  = array_flip(self::$authVars);
		self::$groupVars = array_flip(self::$groupVars);

		if (self::$maxIdleTime<1 && self::$maxLifeTime<1) W('At least one of the SESSION::$max*Time variables must be strictly positive.');

		if (mt_rand(1, self::$gcProbabilityDenominator) <= self::$gcProbabilityNumerator)
		{
			$adapter = new SESSION('0lastGC');
			$i = $adapter->read();
			$j = max(self::$maxIdleTime, self::$maxLifeTime);
			if ($j && $_SERVER['REQUEST_TIME'] - $i > $j)
			{
				$adapter->write($_SERVER['REQUEST_TIME']);
				header('Connection: close');
				register_shutdown_function(array(__CLASS__, 'gc'), $j);
			}
			unset($adapter);
		}

		if (isset($_COOKIE['SID']))
		{
			self::setSID($_COOKIE['SID']);
			self::$adapter = new SESSION(self::$SID);
			$i = self::$adapter->read();
		}
		else $i = false;

		if ($i)
		{
			$i = unserialize($i);
			self::$lastseen =  $i[0];
			self::$birthtime = $i[1];
			if (self::$maxIdleTime && $_SERVER['REQUEST_TIME'] - self::$lastseen > self::$maxIdleTime)
			{
				// Session has idled
				self::onIdle();
				self::$isIdled = true;
			}
			else if (self::$maxLifeTime && $_SERVER['REQUEST_TIME'] - self::$birthtime > self::$maxLifeTime)
			{
				// Session has expired
				self::onExpire();
			}
			else self::$DATA =& $i[2];

			if (isset($_SERVER['HTTPS']) && (!isset($_COOKIE['SSL']) || $i[3] != $_COOKIE['SSL'])) self::regenerateId(true);
			else
			{
				self::$sslid = $i[3];

				if ('-' == self::$sslid[0] && isset($_SERVER['HTTPS']))
				{
					self::$sslid = patchwork::uniqid();
					setcookie('SSL', self::$sslid, 0, self::$cookiePath, self::$cookieDomain, true , true);
					unset($_SERVER['HTTP_IF_NONE_MATCH']);
					unset($_SERVER['HTTP_IF_MODIFIED_SINCE']);
				}
			}
		}
		else self::regenerateId(true);
	}

	protected static function setSID($SID)
	{
		if (self::$IPlevel)
		{
			// TODO : handle ipv6 ?
			$IPs = (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '')
				. ',' . (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '')
				. ',' . (isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : '');

			preg_match_all('/(?<![\.\d])\d+(?:\.\d+){'.(self::$IPlevel-1).'}/u', $IPs, $IPs);

			$IPs = implode(',', $IPs[0]);
		}
		else $IPs = '';

		self::$SID = md5($SID .'-'. $IPs .'-'. (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '') .'-'. substr($GLOBALS['patchwork_token'], 1));
	}

	protected static function onIdle()
	{
		self::regenerateId(true);
	}

	protected static function onExpire()
	{
		self::onIdle();
	}

	/* Adapter */

	protected $handle;
	protected $path;

	protected function __construct($sid)
	{
		$this->path = patchwork::$cachePath . '0/'. $sid[0] .'/'. substr($sid, 1) .'.session';

		patchwork::makeDir($this->path);

		$this->handle = fopen($this->path, 'a+b');
		flock($this->handle, LOCK_EX);
	}

	function __destruct()
	{
		if ($this->handle)
		{
			$this->write(serialize(array(
				self::$isIdled ? self::$lastseen : $_SERVER['REQUEST_TIME'],
				self::$birthtime,
				self::$DATA,
				self::$sslid
			)));

			fclose($this->handle);
		}
	}

	protected function read()
	{
		return stream_get_contents($this->handle);
	}

	protected function write($value)
	{
		ftruncate($this->handle, 0);
		fwrite($this->handle, $value);
	}

	protected function reset()
	{
		ftruncate($this->handle, 0);
		fclose($this->handle);
		$this->handle = false;

		unlink($this->path);
	}

	static function gc($lifetime)
	{
		foreach (glob(patchwork::$cachePath . '0/?/*.session', GLOB_NOSORT) as $file)
		{
			if ($_SERVER['REQUEST_TIME'] - filemtime($file) > $lifetime) unlink($file);
		}
	}
}
