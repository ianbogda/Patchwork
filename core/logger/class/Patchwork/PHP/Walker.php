<?php /****************** vi: set fenc=utf-8 ts=4 sw=4 et: *****************
 *
 *   Copyright : (C) 2011 Nicolas Grekas. All rights reserved.
 *   Email     : p@tchwork.org
 *   License   : http://www.gnu.org/licenses/lgpl.txt GNU/LGPL
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU Lesser General Public License as
 *   published by the Free Software Foundation, either version 3 of the
 *   License, or (at your option) any later version.
 *
 ***************************************************************************/

namespace Patchwork\PHP;

/**
 * Walker implements a mechanism to generically traverse any PHP variable.
 *
 * It takes internal references into account, recursive or non-recursive, without preempting any
 * special use of the discovered data. It exposes only one public method ->walk(), which triggers
 * the traversal. It also has a public property ->checkInternalRefs set to true by default, to
 * disable the check for internal references if the mechanism is considered too expensive.
 * Checking recursive references and object/resource can not be disabled but is much lighter.
 */
abstract class Walker
{
    public

    $checkInternalRefs = true;

    protected

    $depth = 0,
    $counter = 0,
    $arrayType = 0,
    $refMap = array(),
    $refPool = array(),
    $valPool = array(),
    $objPool = array(),
    $arrayPool = array();

    protected static

    $tag,
    $token;

    abstract protected function dumpRef($is_soft, $ref_counter = null, &$ref_value = null);
    abstract protected function dumpScalar($val);
    abstract protected function dumpString($str, $is_key);
    abstract protected function dumpObject($obj);
    abstract protected function dumpResource($res);


    function walk(&$a)
    {
        if (empty(self::$tag))
        {
            self::$tag = (object) array();
            self::$token = md5(mt_rand() . spl_object_hash(self::$tag), true);
            self::$tag = array(self::$token => self::$tag);
        }

        $this->arrayType = $this->counter = $this->depth = 0;
        $this->walkRef($a);
    }

    protected function walkRef(&$a)
    {
        ++$this->counter;

        if (is_array($a)) return $this->walkArray($a);

        $v = $a;

        if ($this->checkInternalRefs && 1 < $this->counter)
        {
            $this->refPool[$this->counter] =& $a;
            $this->valPool[$this->counter] = $a;
            $a = self::$tag;
        }

        switch (true)
        {
        case true  === $v:
        case false === $v:
        case null === $v:
        case is_int($v):
        case is_float($v): $this->dumpScalar($v); break;
        case is_string($v): $this->dumpString($v, false); break;

        case is_object($v): $h = pack('H*', spl_object_hash($v)); // no break;
        default: isset($h) || $h = (int) substr((string) $v, 13); // is_resource() is not reliable (see http://php.net/is_resource#103942), so make it the default case

            if (empty($this->objPool[$h])) $this->objPool[$h] = $this->counter;
            else return $this->dumpRef(true, $this->refMap[$this->counter] = $this->objPool[$h], $v);

            $t = $this->arrayType;
            $this->arrayType = 0;
            if (isset($h[0])) $this->dumpObject($v);
            else $this->dumpResource($v);
            $this->arrayType = $t;
        }
    }

    protected function walkArray(&$a)
    {
        if (isset($a[self::$token]))
        {
            if (self::$tag[self::$token] === $c = $a[self::$token])
            {
                if (empty($a['ref_counter']))
                {
                    $a[] = -$this->counter;
                    return $this->dumpRef(false);
                }

                $c = $a['ref_counter'];
                unset($a);
                $a = $this->valPool[$c];
            }

            $this->refMap[-$this->counter] = $c;
            return $this->dumpRef(false, $c, $a);
        }

        if ($this->checkInternalRefs) $token = self::$token;
        else
        {
/**/        if (PHP_VERSION_ID >= 50206)
/**/        {
                if (0 === $this->arrayType)
                {
                    // Detect recursive arrays by catching recursive count warnings
                    $this->arrayType = 1;
                    set_error_handler(array($this, 'catchRecursionWarning'));
                    count($a, COUNT_RECURSIVE);
                    restore_error_handler();
                }
                if (2 === $this->arrayType) $token = self::$token;
/**/        }
/**/        else
/**/        {
                $token = self::$token;
/**/        }
        }

        $len = count($a);

        if (isset($token))
        {
            $a[$token] = $this->counter;
            $this->arrayPool[] =& $a;
        }

        $this->walkHash('array:' . $len, $a);
    }

    protected function walkHash($type, &$a)
    {
        ++$this->depth;

        foreach ($a as $k => &$a)
        {
            if ($k === self::$token) continue;
            $this->dumpString($k, true);
            $this->walkRef($a);
        }

        if (--$this->depth) return array();
        else return $this->cleanRefPools();
    }

    protected function cleanRefPools()
    {
        $refs = array();

        foreach ($this->refPool as $k => &$v)
        {
            $len = $v;
            $v = $this->valPool[$k];
            if (isset($len[0]))
            {
                unset($len['ref_counter']);
                $refs[$k] = array_slice($len, 1);
            }
        }

        $this->refPool = $this->valPool = $this->objPool = array();
        foreach ($this->refMap as $len => $k) $refs[$k][] = $len;
        foreach ($this->arrayPool as &$a) unset($a[self::$token]);
        $this->arrayPool = $this->refMap = array();

        return $refs;
    }

    protected function catchRecursionWarning()
    {
        $this->arrayType = 2;
    }
}