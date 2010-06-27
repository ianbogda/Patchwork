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


class patchwork_tokenizer_superPositioner extends patchwork_tokenizer_classInfo
{
	protected

	$level,
	$isTop,
	$privateToken,
	$callbacks = array(
		'tagClass'   => array(T_CLASS, T_INTERFACE),
		'tagPrivate' => T_PRIVATE,
	);


	function __construct(parent $parent, $level, $isTop)
	{
		$this->initialize($parent);
		$this->level = $level;
		$this->isTop = $isTop;
	}

	protected function tagClass(&$token)
	{
		$this->register(array(
			'tagClassName' => T_STRING,
			'tagScopeOpen' => T_SCOPE_OPEN,
		));

		if ($token['classIsFinal'])
		{
			$final = array_pop($this->tokens);

			if (isset($final[2]))
			{
				$token[2] = $final[2] . (isset($token[2]) ? $token[2] : '');
			}
		}
	}

	protected function tagClassName(&$token)
	{
		$this->unregister(array('tagClassName' => T_STRING));
		$token[1] .= '__' . (0 <= $this->level ? $this->level : '00');
		$this->class[0]['classKey'] = strtolower($token[1]);
		0 <= $this->level && $this->register(array('tagSelfName' => T_STRING));
	}

	protected function tagSelfName(&$token)
	{
		if (0 === strcasecmp($this->class[0]['className'], $token[1]))
		{
			$token[1] .= '__' . ($this->level ? $this->level - 1 : '00');
		}
	}

	protected function tagScopeOpen(&$token)
	{
		$this->unregister(array(
			'tagSelfName'  => T_STRING,
			'tagScopeOpen' => T_SCOPE_OPEN,
		));

		return 'tagScopeClose';
	}

	protected function tagPrivate(&$token)
	{
		// "private static" methods or properties are problematic when considering class superposition.
		// To work around this, we change them to "protected static", and warn about it
		// (except for files in the include path). Side effects exist but should be rare.

		// Look backward and forward for the "static" keyword
		if (T_STATIC === $this->prevType) $this->fixPrivate($token);
		else
		{
			$this->privateToken =& $token;
			$this->register('tagStatic');
		}
	}

	protected function tagStatic(&$token)
	{
		$this->unregister(__FUNCTION__);

		if (T_STATIC === $token[0])
		{
			$this->fixPrivate($this->privateToken);
		}

		unset($this->privateToken);
	}

	protected function fixPrivate(&$token)
	{
		$token[1] = 'protected';
		$token[0] = T_PROTECTED;

		if (0 <= $this->level)
		{
			$this->setError("Private static methods or properties are banned, please use protected static ones instead");
		}
	}

	protected function tagScopeClose(&$token)
	{
		$class =& $token['class'];

		if ($class['classIsFinal'])
		{
			$token[1] .= "final {$class['classType']} {$class['className']} extends {$class['classKey']} {}";
		}
		else
		{
			if ($this->isTop)
			{
				// FIXME: same fix as commit b87854 needed
				$token[1] .= ($class['classIsAbstract'] ? 'abstract ' : '')
					. "{$class['classType']} {$class['className']} extends {$class['classKey']} {}"
					. "\$GLOBALS['{$this->isTop}']['" . strtolower($class['className']) . "']=1;";
			}

			if ($class['classIsAbstract'])
			{
				$token[1] .= "\$GLOBALS['patchwork_abstract']['{$class['classKey']}']=1;";
			}
		}
	}
}
