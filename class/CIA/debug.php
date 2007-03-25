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
	static $sleep = 500; // (ms)
	static $period = 5;  // (s)

	static function checkCache()
	{
		$GLOBALS['version_id'] *= -1;

		CIA_DIRECT && isset($_GET['d$']) && self::debugWin();

		if (CIA_CHECK_SOURCE && !CIA_DIRECT)
		{
			if ($h = @fopen('./.debugLock', 'xb'))
			{
				flock($h, LOCK_EX);

				@unlink('./.config.zcache.php');

				foreach (glob('./.*.1*.' . $GLOBALS['cia_paths_token'] . '.zcache.php', GLOB_NOSORT) as $cache)
				{
					$file = str_replace('%1', '%', str_replace('%2', '_', strtr(substr($cache, 3, -12-strlen($GLOBALS['cia_paths_token'])), '_', '/')));
					$level = substr(strrchr($file, '.'), 2);

					$file = substr($file, 0, -(2 + strlen($level)));
					if ('-' == substr($level, -1))
					{
						$level = -$level;
						$file = substr($file, 6);
					}

					$file = $GLOBALS['cia_include_paths'][count($GLOBALS['cia_paths']) - $level - 1] .'/'. $file;

					if (!file_exists($file) || filemtime($file) >= filemtime($cache)) @unlink($cache);
				}

				fclose($h);
			}
			else
			{
				$h = fopen('./.debugLock', 'rb');
				flock($h, LOCK_SH);
				fclose($h);
			}

			@unlink('./.debugLock');
		}
	}

	static function debugWin()
	{
		$S = isset($_GET['stop']);
		$S && ob_start('ob_gzhandler', 8192);

		header('Content-Type: text/html; charset=UTF-8');
		header('Cache-Control: max-age=0,private,must-revalidate');

		?><html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<title>Debug Window</title>
<style type="text/css">
body
{
	margin: 0px;
	padding: 0px;
}
pre
{
	font-family: Arial;
	font-size: 10px;
	border-top: 1px solid black;
	margin: 0px;
	padding: 5px;
}
pre:hover
{
	background-color: #D9E4EC;
}
</style>
<script type="text/javascript">/*<![CDATA[*/
function Z()
{
	scrollTo(0, window.innerHeight||(document.documentElement||document.body).scrollHeight);
}
//]]></script>
</head>
<body><?php

		ignore_user_abort($S);
		@set_time_limit(0);

		ini_set('error_log', './error.log');
		$error_log = ini_get('error_log');
		$error_log = $error_log ? $error_log : './error.log';
		echo str_repeat(' ', 512), // special MSIE
			'<pre>';
		$S||flush();

		$sleep = max(100, (int) self::$sleep);
		$i = $period = max(1, (int) 1000*self::$period / $sleep);
		$sleep *= 1000;
		while (1)
		{
			clearstatcache();
			if (is_file($error_log))
			{
				echo '<b></b>'; // Test the connexion
				$S||flush();

				$h = @fopen($error_log, 'r');
				while (!feof($h))
				{
					$a = fgets($h);

					if ('[' == $a[0] && '] PHP ' == substr($a, 21, 6))
					{
						$b = strpos($a, ':', 28);
						$a = substr($a, 0, 23)
							. '<script type="text/javascript">/*<![CDATA[*/
		focus()
		L=opener&&opener.document.getElementById(\'debugLink\')
		L=L&&L.style
		if(L)
		{
		L.backgroundColor=\'red\'
		L.fontSize=\'18px\'
		}
		//]]></script><span style="color:red;font-weight:bold">'
							. substr($a, 23, $b-23)
							. '</span>'
							. preg_replace_callback(
								"'" . preg_quote(htmlspecialchars(CIA_PROJECT_PATH) . DIRECTORY_SEPARATOR . '.')
									. "([^\\\\/]+)\.[01]([0-9]+)(-?)\.{$GLOBALS['cia_paths_token']}\.zcache\.php'",
								array(__CLASS__, 'filename'),
								substr($a, $b)
							);
					}

					echo $a;
					if (connection_aborted()) break;
				}
				fclose($h);

				echo '<script type="text/javascript">/*<![CDATA[*/Z()//]]></script>';
				$S||flush();

				unlink($error_log);
			}
			else if (!--$i)
			{
				$i = $period;
				echo '<b></b>'; // Test the connexion
				$S||flush();
			}

			if ($S)
			{
				echo '<script type="text/javascript">/*<![CDATA[*/scrollTo(0,0);if(window.opener&&opener.E&&opener.E.buffer.length)document.write(opener.E.buffer.join("")),opener.E.buffer=[]//]]></script>';
				break;
			}

			usleep($sleep);
		}

		exit;
	}

	static function filename($m)
	{
		return $GLOBALS['cia_include_paths'][count($GLOBALS['cia_paths']) - ((int)($m[3].$m[2])) - 1]
			. DIRECTORY_SEPARATOR
			. str_replace('%1', '%', str_replace('%2', '_', strtr($m[1], '_', DIRECTORY_SEPARATOR)));
	}
}