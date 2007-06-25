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


define('pC_PIPE',	'0');
define('pC_AGENT',	'1');
define('pC_ECHO',	'2');
define('pC_EVALECHO',	'3');
define('pC_SET',	'4');
define('pC_ENDSET',	'5');
define('pC_JUMP',	'6');
define('pC_IF',		'7');
define('pC_LOOP',	'8');
define('pC_NEXT',	'9');

class extends ptlCompiler
{
	protected $watch = 'public/templates/js';

	protected $serverMode = false;
	protected $setStack = array();
	protected $stack = array();

	protected $jscode = array();
	protected $modifiers = array();
	protected $jsreserved = array(
		'abstract','boolean','break','byte',
		'case','catch','char','class',
		'const','continue','default','delete',
		'do','double','else','export',
		'extends','false','final','finally',
		'float','for','function','goto',
		'if','implements','in','instanceof',
		'int','long','native','new',
		'null','package','private','protected',
		'public','return','short','static',
		'super','switch','synchronized','this',
		'throw','throws','transient','true',
		'try','typeof','var','void',
		'while','with',
	);

	protected function makeCode(&$code)
	{
		if ($m = array_unique($this->modifiers))
		{
			sort($m);
			$m = implode('.', $m);
			array_unshift($this->jscode, pC_PIPE, $this->quote($m));
		}

		return implode(',', $this->jscode);
	}

	protected function makeModifier($name)
	{
		$this->modifiers[] = $name;
		return 'P$' . $name;
	}

	protected function addAGENT($end, $inc, &$args, $is_exo)
	{
		if ($end) return false;

		$this->pushCode('');

		$keys = false;
		$meta = $is_exo ? 3 : 2;

		if (preg_match('/^\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'$/s', $inc))
		{
			eval("\$inc=$inc;");

			list($appId, $base, $inc, $keys, $a) = patchwork_resolveTrace::call($inc);

			foreach ($a as $k => &$v) $args[$k] = $this->quote($v);

			if (false !== $base)
			{
				if (!$is_exo)
				{
					W("Template Security Restriction Error: an EXOAGENT ({$base}{$inc}) is called with AGENT on line " . $this->getLine());
					exit;
				}

				$meta = array($appId, $this->quote($base));
				$meta = '[' . implode(',', $meta) . ']';
			}
			else if ($is_exo)
			{
				W("Template Security Restriction Error: an AGENT ({$inc}) is called with EXOAGENT on line " . $this->getLine());
				exit;
			}
			else $meta = 1;

			array_walk($keys, array($this, 'quote'));
			$keys = implode(',', $keys);

			$inc = jsquote($inc);
		}

		$a = '';
		$comma = '';
		foreach ($args as $k => &$v)
		{
			$a .= in_array($k, $this->jsreserved) ? "$comma'$k':$v" : "$comma$k:$v";
			$comma = ',';
		}
		$a = '{' . $a . '}';

		array_push($this->jscode, pC_AGENT, $this->quote($inc), $this->quote($a), false === $keys ? 0 : "[$keys]", $meta);

		return true;
	}

	protected function addSET($end, $name, $type)
	{
		$this->pushCode('');

		if ($end)
		{
			$type = array_pop($this->setStack);
			$name = $type[0];
			$type = $type[1];

			if ($type == 'g') $type = 1;
			else if ($type == 'a') $type = 0;
			else $type = strlen($type) + 2;

			array_push($this->jscode, pC_ENDSET, $type, "'" . $name . "'");
		}
		else
		{
			array_push($this->setStack, array($name, $type));
			array_push($this->jscode, pC_SET);
		}

		return true;
	}

	protected function addLOOP($end, $var)
	{
		$this->pushCode('');

		if ($end)
		{
			$a = array_pop($this->stack);
			$b = count($this->jscode) - $a;
			if (-1 == $this->jscode[$a])
			{
				array_push($this->jscode, pC_NEXT, $b);
				$this->jscode[$a] = $b + 2;
			}
			else $this->jscode[$a] = $b;
		}
		else
		{
			array_push($this->stack, count($this->jscode) + 2);
			array_push($this->jscode, pC_LOOP, $this->quote($var), -1);
		}

		return true;
	}

	protected function addIF($end, $elseif, $expression)
	{
		if ($elseif && $end) return false;

		$this->pushCode('');

		if ($end)
		{
			$a = array_pop($this->stack);
			$b = count($this->jscode) - $a;
			if (-3 == $this->jscode[$a]) do
			{
				$this->jscode[$a] = $b;
				$b += $a;
				$a = array_pop($this->stack);
				$b -= $a;
			}
			while (-3 == $this->jscode[$a]);

			$this->jscode[$a] = $b;
		}
		else
		{
			if ($elseif) $this->addELSE(false);

			array_push($this->stack, count($this->jscode) + 2);
			array_push($this->jscode, pC_IF, $this->quote($expression), $elseif ? -3 : -2);
		}

		return true;
	}

	protected function addELSE($end)
	{
		if ($end) return false;

		$this->pushCode('');

		$a = array_pop($this->stack);
		$b = count($this->jscode) - $a;
		if (-1 == $this->jscode[$a])
		{
			array_push($this->stack, $a + $b + 3);
			array_push($this->jscode, pC_NEXT, $b, pC_JUMP, -2);
			$this->jscode[$a] = $b + 4;
		}
		else
		{
			array_push($this->stack, $a + $b + 1);
			array_push($this->jscode, pC_JUMP, $this->jscode[$a]);
			$this->jscode[$a] = $b + 2;
		}

		return true;
	}

	protected function getEcho($str)
	{
		if ("'" == $str[0] || (string) $str === (string) ($str-0))
		{
			if ("''" != $str) array_push($this->jscode, pC_ECHO, $str);
		}
		else
		{
			$this->pushCode('');
			array_push($this->jscode, pC_EVALECHO, $this->quote($str));
		}

		return '';
	}

	protected function getConcat($array)
	{
		return implode('+', $array);
	}

	protected function getVar($name, $type, $prefix, $forceType)
	{
		if ((string) $name === (string) ($name-0)) return $name;

		switch ($type)
		{
			case "'":
				$result = jsquote($name);
				break;

			case '$':
				$result = 'v' . str_repeat('.$', substr_count($prefix, '$')) . $this->getJsAccess($name);
				break;

			case 'd':
			case 'a':
			case 'g':
				$result = ''!==(string) $prefix ? "z('$name',$prefix" .( $type=='g' ? ',1' : '' ). ')' : ($type . $this->getJsAccess($name));
				if ('g.__BASE__' == $result) $result = 'r';
				break;

			case '':
				$result = 'v' . $this->getJsAccess($name);
				break;

			default:
				$result = $type . $this->getJsAccess($name);
		}

		if ('number' == $forceType) $result = "num($result)";
		else if ('concat' == $this->mode && "'" != $result[0]) $result = "str($result)";

		return $result;
	}

	protected function getJsAccess($name)
	{
		return strlen($name) ? ( preg_match('"[^a-zA-Z0-9_\$]"', $name) || in_array($name, $this->jsreserved) ? "['$name']" : ".$name" ) : '';
	}

	protected function quote(&$a)
	{
		return $a = jsquote($a, true, '"');
	}
}