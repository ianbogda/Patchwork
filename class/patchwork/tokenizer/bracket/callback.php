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


// FIXME: handle when $callbackIndex < 0

class patchwork_tokenizer_bracket_callback extends patchwork_tokenizer_bracket
{
	protected

	$callbackIndex,
	$lead = 'patchwork_alias::resolve(',
	$tail = ')',
	$nextTail = '',
	$alias = array();


	function __construct(patchwork_tokenizer $parent, $callbackIndex, $alias = array())
	{
		if (0 < $callbackIndex)
		{
			$this->alias = $alias;
			$this->callbackIndex = $callbackIndex - 1;
			$this->initialize($parent);
		}
	}

	protected function onOpen(&$token)
	{
		if (0 === $this->callbackIndex) $this->addLead($token[1]);
	}

	protected function onReposition(&$token)
	{
		if ($this->bracketIndex === $this->callbackIndex    ) $this->addLead($token[1]);
		if ($this->bracketIndex === $this->callbackIndex + 1) $this->addTail($token[1]);
	}

	protected function onClose(&$token)
	{
		if ($this->bracketIndex === $this->callbackIndex) $this->addTail($token[1]);
	}

	protected function addLead(&$token)
	{
		$t =& $this->getNextToken();

		if (T_CONSTANT_ENCAPSED_STRING === $t[0])
		{
			$a = $this->getNextToken(1);

			if (',' === $a[0] || ')' === $a[0])
			{
				$a = strtolower(substr($t[1], 1, -1));

				if (isset($this->alias[$a]))
				{
					$a = $this->alias[$a];
					$a = explode('::', $a, 2);

					if (1 === count($a)) $t[1] = "'{$a[0]}'";
					else if (empty($this->class->nsName) || strcasecmp($a[0], $this->class->nsName))
					{
						$t = ')';
						$this->tokenUnshift(
							array(T_CONSTANT_ENCAPSED_STRING, "'{$a[1]}'"), ',',
							array(T_CONSTANT_ENCAPSED_STRING, "'{$a[0]}'"), '(',
							array(T_ARRAY, 'array')
						);
					}
				}

				return;
			}
		}
		else if (T_FUNCTION === $t[0])
		{
			return; // Closure
		}
		else if (T_ARRAY === $t[0])
		{
			$i = $this->index;
			$t =& $this->token;
			$b = 0;

			while (isset($t[++$i]))
			{
				if ('(' === $t[$i][0]) ++$b;
				else if (')' === $t[$i][0] && --$b <= 0)
				{
					++$i;
					while (isset($t[$i], self::$sugar[$t[$i][0]])) ++$i;

					if ($b < 0 || !isset($t[$i]) || ',' === $t[$i][0] || ')' === $t[$i][0])
					{
						return;
					}

					break;
				}
			}
		}

		$token .= $this->lead;
		$this->nextTail = $this->tail;
	}

	protected function addTail(&$token)
	{
		$token = $this->nextTail . $token;
		$this->nextTail = '';
	}
}
