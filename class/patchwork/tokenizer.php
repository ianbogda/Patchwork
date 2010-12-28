<?php /*********************************************************************
 *
 *   Copyright : (C) 2010 Nicolas Grekas. All rights reserved.
 *   Email     : p@tchwork.org
 *   License   : http://www.gnu.org/licenses/agpl.txt GNU/AGPL
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License as
 *   published by the Free Software Foundation, either version 3 of the
 *   License, or (at your option) any later version.
 *
 ***************************************************************************/


// New tokens since PHP 5.3
defined('T_GOTO')         || patchwork_tokenizer::defineNewToken('T_GOTO');
defined('T_DIR' )         || patchwork_tokenizer::defineNewToken('T_DIR');
defined('T_NS_C')         || patchwork_tokenizer::defineNewToken('T_NS_C');
defined('T_NAMESPACE')    || patchwork_tokenizer::defineNewToken('T_NAMESPACE');
defined('T_NS_SEPARATOR') || patchwork_tokenizer::defineNewToken('T_NS_SEPARATOR');

// Primary token matching closing braces opened with T_CURLY_OPEN or T_DOLLAR_OPEN_CURLY_BRACES
patchwork_tokenizer::defineNewToken('T_CURLY_CLOSE');

// Primary token matching the @-operator
patchwork_tokenizer::defineNewToken('T_SILENCE');

// Sub-token matching multilines sugar tokens (T_WHITESPACE, T_COMMENT, T_DOC_COMMENT and T_SILENCE)
patchwork_tokenizer::defineNewToken('T_MULTILINE_SUGAR');

class patchwork_tokenizer
{
	protected

	$line = 0,
	$code,
	$position,
	$tokens,
	$prevType,
	$anteType,

	$tokenRegistry    = array(),
	$callbackRegistry = array(),

	$parent,
	$depends = array(),
	$shared = array(
		'line',
		'code',
		'depends',
		'position',
		'tokens',
		'prevType',
		'anteType',
		'tokenRegistry',
		'callbackRegistry',
		'tokenizerError',
		'nextRegistryPosition',
	);


	private

	$tokenizerError       = false,
	$registryPosition     = 0,
	$nextRegistryPosition = 0;


	protected static

	$sugar = array(
		T_WHITESPACE => 1,
		T_COMMENT    => 1, T_DOC_COMMENT => 1,
		T_SILENCE    => 1, '@'           => 1,
	);


	function __construct(self $parent = null)
	{
		$parent || $parent = $this;
		$this->initialize($parent);
	}

	protected function initialize(self $parent)
	{
		$this->parent = $parent;
		is_array($this->depends) || $this->depends = (array) $this->depends;

		foreach ($this->depends as $parent)
		{
			if (!isset($this->parent->depends[$parent]))
			{
				trigger_error(get_class($this) . ' tokenizer depends on a not initialized one: ' . $parent);
				return;
			}
		}

		$this->depends = array_flip($this->depends);
		$parent = get_class($this);

		while (!isset($this->depends[$parent]) && false !== $parent)
		{
			$this->depends[$parent] = 1;
			$parent = get_parent_class($parent);
		}

		if ($this !== $this->parent)
		{
			$this->parent->depends += $this->depends;

			foreach (array_keys($this->parent->shared) as $parent)
				$this->$parent =& $this->parent->$parent;

			$this->parent->shared += array_flip((array) $this->shared);
			$this->shared =& $this->parent->shared;
		}
		else
		{
			$this->shared = array_flip((array) $this->shared);
		}

		$this->registryPosition = $this->nextRegistryPosition;
		$this->nextRegistryPosition += 100000;

		empty($this->callbacks) || $this->register();
	}

	static function defineNewToken($name)
	{
		static $offset = 0;
		define($name, --$offset);
	}

	protected function register($method = null)
	{
		null === $method && $method = $this->callbacks;

		$sort = array();

		foreach ((array) $method as $method => $type)
		{
			if (is_int($method))
			{
				isset($sort['']) || $sort[''] =& $this->callbackRegistry;
				$this->callbackRegistry[++$this->registryPosition] = array($this, $type, 0);
			}
			else foreach ((array) $type as $s => $type)
			{
				foreach ((array) $type as $type)
				{
					isset($sort[$type]) || $sort[$type] =& $this->tokenRegistry[$type];
					$this->tokenRegistry[$type][++$this->registryPosition] = array($this, $method, 0 === $s || (0 < $s && is_int($s)) ? 0 : $s);
				}
			}
		}

		foreach ($sort as &$sort) ksort($sort);
	}

	protected function unregister($method = null)
	{
		null === $method && $method = $this->callbacks;

		foreach ((array) $method as $method => $type)
		{
			if (is_int($method))
			{
				foreach ($this->callbackRegistry as $k => $v)
					if (array($this, $type, 0) === $v)
						unset($this->callbackRegistry[$k]);
			}
			else foreach ((array) $type as $s => $type)
			{
				foreach ((array) $type as $type)
				{
					if (isset($this->tokenRegistry[$type]))
					{
						foreach ($this->tokenRegistry[$type] as $k => $v)
							if (array($this, $method, 0 === $s || (0 < $s && is_int($s)) ? 0 : $s) === $v)
								unset($this->tokenRegistry[$type][$k]);

						if (!$this->tokenRegistry[$type]) unset($this->tokenRegistry[$type]);
					}
				}
			}
		}
	}

	protected function setError($message)
	{
		if (!$this->tokenizerError)
		{
			$this->tokenizerError = array($message, (int) $this->line, get_class($this));
		}
	}

	function getError()
	{
		return $this->tokenizerError;
	}

	function tokenize($code)
	{
		if ($this->parent !== $this) return $this->parent->tokenize($code);

		if ('' === $code) return $code;

		$tRegistry =& $this->tokenRegistry;
		$cRegistry =& $this->callbackRegistry;

		$this->code = $this->getTokens($code);

		$code     =& $this->code;
		$line     =& $this->line;     $line     = 1;
		$i        =& $this->position; $i        = 0;
		$tokens   =& $this->tokens;   $tokens   = array(array(), array(''));
		$prevType =& $this->prevType; $prevType = false;
		$anteType =& $this->anteType; $anteType = false;

		$j        = 0;
		$curly    = 0;
		$strCurly = array();

		while (isset($code[$i]))
		{
			$lines = 0;
			$token =& $code[$i];
			unset($code[$i++]);

			if (isset($token[1]))
			{
				switch ($token[0])
				{
				case T_WHITESPACE:
				case T_COMMENT:
				case T_DOC_COMMENT:
				case T_SILENCE:
					$lines = substr_count($token[1], "\n");

					if (isset($tRegistry[$token[0]]))
					{
						foreach ($tRegistry[$token[0]] as $c)
							if (0 === $c[2] || ($lines && T_MULTILINE_SUGAR === $c[2]))
								if (false === $c[0]->{$c[1]}($token)) continue 3;
					}

					$tokens[1][++$j] =& $token[1];

					$line += $lines;
					continue 2;

				case T_CONSTANT_ENCAPSED_STRING:
				case T_ENCAPSED_AND_WHITESPACE:
				case T_OPEN_TAG_WITH_ECHO:
				case T_INLINE_HTML:
				case T_CLOSE_TAG:
				case T_OPEN_TAG:
					$lines = substr_count($token[1], "\n");
					break;

				case T_DOLLAR_OPEN_CURLY_BRACES:
				case T_CURLY_OPEN:
					$strCurly[] = $curly;
					$curly = 0;
					break;
				}
			}
			else
			{
				$token = array($token, $token);

				switch ($token[0])
				{
				case '{': ++$curly; break;
				case '}':
					if (0 > --$curly)
					{
						$token[0] = T_CURLY_CLOSE;
						$curly    = array_pop($strCurly);
					}
					break;
				case '@':
					$code[--$i] = array(T_SILENCE, '@');
					continue 2;
				}
			}

			if ($cRegistry || isset($tRegistry[$token[0]]))
			{
				if (!$c = $cRegistry)
				{
					$c = $tRegistry[$token[0]];
				}
				else if (isset($tRegistry[$token[0]]))
				{
					$c += $tRegistry[$token[0]];
					ksort($c);
				}

				foreach ($c as $c)
					if (0 === $c[2] || (isset($token[2]) && $token[2] === $c[2]))
						if (false === $c[0]->{$c[1]}($token)) continue 2;
			}

			$tokens[0][++$j] =& $token[0];
			$tokens[1][  $j] =& $token[1];
			$line += $lines;

			$anteType = $prevType;
			$prevType = $token[0];
		}

		// Free memory thanks to copy-on-write
		$j      = $tokens[1];
		$tokens = array();
		$line   = 0;

		return $j;
	}

	protected function getTokens($code)
	{
		return $this->parent === $this ? token_get_all($code) : $this->parent->getTokens($code);
	}

	static function export($a)
	{
		if (is_array($a))
		{
			if ($a)
			{
				$i = 0;
				$b = array();

				foreach ($a as $k => $a)
				{
					if (is_int($k) && $k >= 0)
					{
						$b[] = ($k !== $i ? $k . '=>' : '') . self::export($a);
						$i = $k+1;
					}
					else
					{
						$b[] = self::export($k) . '=>' . self::export($a);
					}
				}

				$b = 'array(' . implode(',', $b) . ')';
			}
			else return 'array()';
		}
		else if (is_object($a))
		{
			$b = array();
			$v = (array) $a;
			foreach ($v as $k => $v)
			{
				if ("\0" === substr($k, 0, 1)) $k = substr($k, 3);
				$b[$k] = $v;
			}

			$b = self::export($b);
			$b = get_class($a) . '::__set_state(' . $b . ')';
		}
		else if (is_string($a))
		{
			if ($a !== strtr($a, "\r\n\0", '---'))
			{
				$b = '"'. str_replace(
					array(  "\\",   '"',   '$',  "\r",  "\n",  "\0"),
					array('\\\\', '\\"', '\\$', '\\r', '\\n', '\\0'),
					$a
				) . '"';
			}
			else
			{
				$b = "'" . str_replace(
					array('\\', "'"),
					array('\\\\', "\\'"),
					$a
				) . "'";
			}
		}
		else if (true  === $a) $b = 'true';
		else if (false === $a) $b = 'false';
		else if (null  === $a) $b = 'null';
		else if (INF   === $a) $b = 'INF';
		else if (NAN   === $a) $b = 'NAN';
		else $b = (string) $a;

		return $b;
	}

	protected function &getNextToken($offset = 0)
	{
		$i = $this->position;

		do while (isset($this->code[$i], self::$sugar[$this->code[$i][0]])) ++$i;
		while ($offset-- > 0 && ++$i);

		isset($this->code[$i]) || $this->code[$i] = array(T_WHITESPACE, '');

		return $this->code[$i];
	}

	function __call($method, $args)
	{
		return call_user_func_array(array($this->parent, $method), $args);
	}
}
