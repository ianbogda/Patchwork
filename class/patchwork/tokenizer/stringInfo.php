<?php /***** vi: set encoding=utf-8 expandtab shiftwidth=4: ****************
 *
 *   Copyright : (C) 2011 Nicolas Grekas. All rights reserved.
 *   Email     : p@tchwork.org
 *   License   : http://www.gnu.org/licenses/agpl.txt GNU/AGPL
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License as
 *   published by the Free Software Foundation, either version 3 of the
 *   License, or (at your option) any later version.
 *
 ***************************************************************************/


// Match T_STRING variants
patchwork_tokenizer::createToken('T_NAME_NS');       // namespace FOO\BAR    - namespace declaration
patchwork_tokenizer::createToken('T_NAME_CLASS');    // class FOO {}         - class or interface declaration
patchwork_tokenizer::createToken('T_NAME_FUNCTION'); // function FOO()       - function or method declaration
patchwork_tokenizer::createToken('T_NAME_CONST');    // const FOO            - class or namespaced const declaration
patchwork_tokenizer::createToken('T_USE_NS');        // FOO\bar              - namespace prefix or "use" aliasing
patchwork_tokenizer::createToken('T_USE_CLASS');     // new foo\BAR - FOO::  - class usage
patchwork_tokenizer::createToken('T_USE_METHOD');    // $a->FOO() - a::BAR() - method call
patchwork_tokenizer::createToken('T_USE_PROPERTY');  // $a->BAR              - property access
patchwork_tokenizer::createToken('T_USE_FUNCTION');  // foo\BAR()            - function call
patchwork_tokenizer::createToken('T_USE_CONST');     // foo::BAR             - class constant access
patchwork_tokenizer::createToken('T_USE_CONSTANT');  // FOO - foo\BAR        - global or namespaced constant access
patchwork_tokenizer::createToken('T_GOTO_LABEL');    // goto FOO - BAR:{}    - goto label
patchwork_tokenizer::createToken('T_TYPE_HINT');     // instanceof foo\BAR - function(foo\BAR $a) - type hint
patchwork_tokenizer::createToken('T_TRUE');          // true
patchwork_tokenizer::createToken('T_FALSE');         // false
patchwork_tokenizer::createToken('T_NULL');          // null


class patchwork_tokenizer_stringInfo extends patchwork_tokenizer
{
    protected

    $inConst   = false,
    $inExtends = false,
    $inParam   = 0,
    $inNs      = false,
    $inUse     = false,
    $nsPrefix  = '',
    $preNsType = 0,
    $callbacks = array(
        'tagString'   => T_STRING,
        'tagConst'    => T_CONST,
        'tagExtends'  => array(T_EXTENDS, T_IMPLEMENTS),
        'tagFunction' => T_FUNCTION,
        'tagNs'       => T_NAMESPACE,
        'tagUse'      => T_USE,
        'tagNsSep'    => T_NS_SEPARATOR,
    );


    function removeNsPrefix()
    {
        if (empty($this->nsPrefix)) return;

        $t =& $this->types;
        end($t);

        $p = array(T_STRING, T_NS_SEPARATOR);
        $j = 0;

        while (null !== $i = key($t))
        {
            if ($p[++$j%2] === $t[$i])
            {
                $this->texts[$i] = '';
                unset($t[$i]);
            }
            else break;

            prev($t);
        }

        $this->nsPrefix = '';
        $this->lastType = $this->preNsType;
    }

    protected function tagString(&$token)
    {
        if (T_NS_SEPARATOR !== $p = $this->lastType) $this->nsPrefix = '';

        switch (strtolower($token[1]))
        {
        case 'true':   return T_TRUE;
        case 'false':  return T_FALSE;
        case 'null':   return T_NULL;
        }

        switch ($p)
        {
        case T_INTERFACE:
        case T_CLASS: return T_NAME_CLASS;
        case T_GOTO:  return T_GOTO_LABEL;

        case '&': if (T_FUNCTION !== $this->penuType) break;
        case T_FUNCTION: return T_NAME_FUNCTION;

        case ',':
        case T_CONST:
            if ($this->inConst) return T_NAME_CONST;

        default:
            if ($this->inNs ) return T_NAME_NS;
            if ($this->inUse) return T_USE_NS;
        }

        $n = $this->getNextToken();

        if (T_NS_SEPARATOR === $n = $n[0])
        {
            if (T_NS_SEPARATOR === $p)
            {
                $this->nsPrefix .= $token[1];
            }
            else
            {
                $this->nsPrefix  = $token[1];
                $this->preNsType = $p;
            }

            return T_USE_NS;
        }

        switch (empty($this->nsPrefix) ? $p : $this->preNsType)
        {
        case ',': if (!$this->inExtends) break;
        case T_NEW:
        case T_EXTENDS:
        case T_IMPLEMENTS: return T_USE_CLASS;
        case T_INSTANCEOF: return T_TYPE_HINT;
        }

        switch ($n)
        {
        case T_DOUBLE_COLON: return T_USE_CLASS;
        case T_VARIABLE:     return T_TYPE_HINT;

        case '(':
            switch ($p)
            {
            case T_OBJECT_OPERATOR:
            case T_DOUBLE_COLON: return T_USE_METHOD;
            default:             return T_USE_FUNCTION;
            }

        case ':':
            if ('{' === $p || ';' === $p) return T_GOTO_LABEL;
            // No break;

        default:
            switch ($p)
            {
            case T_OBJECT_OPERATOR: return T_USE_PROPERTY;
            case T_DOUBLE_COLON:    return T_USE_CONST;

            case '(':
            case ',':
                if (1 === $this->inParam && '&' === $n) return T_TYPE_HINT;
                // No break;
            }
        }

        return T_USE_CONSTANT;
    }

    protected function tagConst(&$token)
    {
        $this->inConst = true;
        $this->register(array('tagConstEnd' => ';'));
    }

    protected function tagConstEnd(&$token)
    {
        $this->inConst = false;
        $this->unregister(array(__FUNCTION__ => ';'));
    }

    protected function tagExtends(&$token)
    {
        $this->inExtends = true;
        $this->register(array('tagExtendsEnd' => '{'));
    }

    protected function tagExtendsEnd(&$token)
    {
        $this->inExtends = false;
        $this->unregister(array(__FUNCTION__ => '{'));
    }

    protected function tagFunction(&$token)
    {
        $this->register(array(
            'tagParamOpenBracket'  => '(',
            'tagParamCloseBracket' => ')',
        ));
    }

    protected function tagParamOpenBracket(&$token)
    {
        ++$this->inParam;
    }

    protected function tagParamCloseBracket(&$token)
    {
        if (0 >= --$this->inParam)
        {
            $this->inParam = 0;
            $this->unregister(array(
                'tagParamOpenBracket'  => '(',
                'tagParamCloseBracket' => ')',
            ));
        }
    }

    protected function tagNs(&$token)
    {
        $t = $this->getNextToken();

        switch ($t[0])
        {
        case T_STRING:
            $this->inNs = true;
            $this->register(array('tagNsEnd' => array('{', ';')));
            // No break;

        case '{':
            return T_NAME_NS;

        case T_NS_SEPARATOR:
            return $this->tagString($token);
        }
    }

    protected function tagNsEnd(&$token)
    {
        $this->inNs = false;
        $this->unregister(array(__FUNCTION__ => array('{', ';')));
    }

    protected function tagUse(&$token)
    {
        if (')' !== $this->lastType)
        {
            $this->inUse = true;
            $this->register(array('tagUseEnd' => ';'));
        }
    }

    protected function tagUseEnd(&$token)
    {
        $this->inUse = false;
        $this->unregister(array(__FUNCTION__ => ';'));
    }

    protected function tagNsSep(&$token)
    {
        if (T_STRING === $this->lastType)
        {
            $this->nsPrefix .= '\\';
        }
        else
        {
            $this->nsPrefix  = '\\';
            $this->preNsType = $this->lastType;
        }
    }
}
