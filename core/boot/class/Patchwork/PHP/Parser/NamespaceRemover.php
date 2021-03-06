<?php // vi: set fenc=utf-8 ts=4 sw=4 et:
/*
 * Copyright (C) 2012 Nicolas Grekas - p@tchwork.com
 *
 * This library is free software; you can redistribute it and/or modify it
 * under the terms of the (at your option):
 * Apache License v2.0 (http://apache.org/licenses/LICENSE-2.0.txt), or
 * GNU General Public License v2.0 (http://gnu.org/licenses/gpl-2.0.txt).
 */

namespace Patchwork\PHP\Parser;

use Patchwork\PHP\Parser;

/**
 * The NamespaceRemover parser backports namespaces introduced in PHP 5.3.
 *
 * It does so by resolving then removing namespace declarations and replacing namespace separators by underscores.
 */
class NamespaceRemover extends Parser
{
    protected

    $aliasAdd   = false,
    $callbacks  = array(
        'tagNs'     => T_NAMESPACE,
        'tagNsSep'  => T_NS_SEPARATOR,
        'tagNsUse'  => array(T_USE_CLASS, T_USE_FUNCTION, T_USE_CONSTANT, T_TYPE_HINT),
        'tagNsName' => array(T_NAME_CLASS, T_NAME_FUNCTION),
        'tagNew'    => T_NEW,
        'tagConst'  => T_CONST,
    ),

    $bracketsCount, $class, $scope, $namespace,
    $dependencies = array(
        'BracketWatcher' => 'bracketsCount',
        'ConstFuncResolver',
        'ClassInfo' => array('class', 'scope', 'namespace'),
        'NamespaceResolver',
    );


    function __construct(parent $parent, $alias_add = false)
    {
        $this->aliasAdd = $alias_add;
        parent::__construct($parent);
    }

    protected function tagNs(&$token)
    {
        if (!isset($token[2][T_NAME_NS])) return;

        $this->register('tagNsEnd');
        $token[1] = ' ';
    }

    protected function tagNsEnd(&$token)
    {
        switch ($token[0])
        {
        case '{': $this->register(array('tagNsClose' => T_BRACKET_CLOSE));
        case ';':
        case $this->prevType:
            $this->unregister(__FUNCTION__);
            if ($this->prevType === $token[0]) return;
        }

        $token[1] = '';
    }

    protected function tagNsClose(&$token)
    {
        $token[1] = '';
    }

    protected function tagNsSep(&$token)
    {
        if (T_STRING === $this->prevType) $token[1] = strtr($token[1], '\\', '_');
        else if (T_NS_SEPARATOR !== $this->prevType) $token[1] = ' ';
    }

    protected function tagNsUse(&$token)
    {
        $token[1] = strtr($token[1], '\\', '_');
    }

    protected function tagNsName(&$token)
    {
        if ($this->namespace)
        {
            switch ($this->scope->type) {case T_CLASS: case T_INTERFACE: case T_TRAIT: return;}

            if (isset($token[2][T_NAME_CLASS]))
            {
                $this->class->nsName = strtr($this->class->nsName, '\\', '_');
                $this->aliasAdd && $this->scope->token[1] .= "{$this->aliasAdd}('{$this->namespace}{$this->class->name}');";
                $this->class->name = $this->class->nsName;
            }

            end($this->texts);
            $this->texts[key($this->texts)] .= strtr($this->namespace, '\\', '_');
        }
    }

    /**
     *  Fixes `new $foo`, when $foo = 'ns\class';
     *
     *  @todo new ${...}, new $foo[...] and new $foo->...
     */
    protected function tagNew(&$token)
    {
        $t =& $this->getNextToken($n);

        if (T_VARIABLE === $t[0])
        {
            $n = $this->getNextToken($n);

            if ('[' !== $n[0] && T_OBJECT_OPERATOR !== $n[0])
            {
                $t[1] = "\${is_string($\x9D={$t[1]})&&($\x9D=strtr(isset($\x9D[0])&&'\\\\'===$\x9D[0]?substr($\x9D,1):$\x9D,'\\\\','_'))?\"\x9D\":\"\x9D\"}";
            }
        }
    }

    protected function tagConst(&$token)
    {
        switch ($this->scope->type)
        {
        case T_OPEN_TAG:
        case T_NAMESPACE:
            $token[1] = 'define(';

            $this->constBracketLevel = $this->bracketsCount;
            $this->register($this->callbacks = array(
                'tagConstEqual' => '=',
                'tagConstEnd' => array(';', ','),
            ));
        }
    }

    protected function tagConstEqual(&$token)
    {
        end($this->types);
        $this->texts[key($this->types)] = "'" . strtr($this->namespace, '\\', '_') . $this->texts[key($this->types)] . "'";
        $token[1] = ',';
    }

    protected function tagConstEnd(&$token)
    {
        if ($this->bracketsCount === $this->constBracketLevel)
        {
            if (';' === $token[0])
            {
                $this->unregister($this->callbacks);
                $token[1] = ")" . $token[1];
            }
            else
            {
                $token[1] = ");define(";
            }
        }
    }
}
