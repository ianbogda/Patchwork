<?php // vi: set fenc=utf-8 ts=4 sw=4 et:
/*
 * Copyright (C) 2012 Nicolas Grekas - p@tchwork.com
 *
 * This library is free software; you can redistribute it and/or modify it
 * under the terms of the (at your option):
 * Apache License v2.0 (http://apache.org/licenses/LICENSE-2.0.txt), or
 * GNU General Public License v2.0 (http://gnu.org/licenses/gpl-2.0.txt).
 */

namespace Patchwork\PHP\Parser\Bracket;

use Patchwork\PHP\Parser;

/**
 * The Bracket_Callback parser participates in catching callbacks for at runtime function overriding.
 *
 * @todo Handle when $callbackIndex <= 0
 * @todo Unlike static callbacks, a function shim can not use its overriden function
 *       through a dynamic callback, because that would lead to unwanted recursion.
 */
class Callback extends Parser\Bracket
{
    protected

    $callbackIndex,
    $lead = 'patchwork_shim_resolve(',
    $tail = ')',
    $nextTail = '',
    $shims = array(),

    $scope, $class,
    $dependencies = array(
        'ConstantInliner' => 'scope',
        'ClassInfo' => 'class',
    );


    function __construct(Parser $parent, $callbackIndex, $shims = array())
    {
        if (0 < $callbackIndex)
        {
            $this->shims = $shims;
            $this->callbackIndex = $callbackIndex - 1;
            parent::__construct($parent);
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
        $t =& $this->getNextToken($a);

        // TODO: optimize more cases with the ConstantExpression parser

        if (T_CONSTANT_ENCAPSED_STRING === $t[0])
        {
            $a = $this->getNextToken($a);

            if (',' === $a[0] || ')' === $a[0])
            {
                $a = strtolower(substr($t[1], 1, -1));

                if (isset($this->shims[$a]))
                {
                    $a = $this->shims[$a];
                    $a = explode('::', $a, 2);

                    if (1 === count($a))
                    {
                        if ($this->class || strcasecmp($a[0], $this->scope->funcC)) $t[1] = "'{$a[0]}'";
                    }
                    else if (empty($this->class->nsName) || strcasecmp($a[0], $this->class->nsName))
                    {
                        $this->unshiftCode("array('{$a[0]}','{$a[1]}'");
                        $t = ')';
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
            $a = $this->index;
            $t =& $this->tokens;
            $b = 0;

            if ($this->targetPhpVersionId >= 50300)
            {
                // TODO: replace 'self' by __CLASS__, check for $this.

                while (isset($t[$a])) switch ($t[$a++][0])
                {
                case '(': ++$b; break;
                case ')':
                    if (0 >= --$b)
                    {
                        $c = $this->getNextToken($a);
                        if (0 > $b || ',' === $c[0] || ')' === $c[0]) return;
                        break;
                    }
                }
            }
        }
        else if (')' === $t[0]) return;
        else if (',' === $t[0]) return;

        $token .= $this->lead;
        $this->nextTail = $this->tail;
    }

    protected function addTail(&$token)
    {
        $token = $this->nextTail . $token;
        $this->nextTail = '';
    }
}
