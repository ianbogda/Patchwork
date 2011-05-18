<?php /****************** vi: set fenc=utf-8 ts=4 sw=4 et: *****************
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


class Patchwork_PHP_Parser_ClassAutoname extends Patchwork_PHP_Parser
{
    protected

    $className,
    $callbacks = array('tagClass' => array(T_CLASS, T_INTERFACE));


    function __construct(parent $parent, $className)
    {
        parent::__construct($parent);

        $this->className = $className;
    }

    protected function tagClass(&$token)
    {
        $t = $this->getNextToken();

        if (T_STRING !== $t[0])
        {
            $this->setError("Class auto-naming is deprecated ({$this->className})", E_USER_DEPRECATED);

            $this->unshiftTokens(
                array(T_WHITESPACE, ' '),
                array(T_STRING, strtr(ltrim($this->className, '\\'), '\\', '_'))
            );

            $this->unregister($this->callbacks);
        }
    }
}