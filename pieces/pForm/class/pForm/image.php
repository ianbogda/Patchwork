<?php /*********************************************************************
 *
 *   Copyright : (C) 2007 Nicolas Grekas. All rights reserved.
 *   Email     : p@tchwork.org
 *   License   : http://www.gnu.org/licenses/gpl.txt GNU/GPL
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 3 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/


class extends pForm_submit
{
	protected $type = 'image';

	protected function init(&$param)
	{
		unset($this->form->rawValues[$this->name]);
		unset($this->form->rawValues[$this->name]); // Double unset against PHP security hole
		parent::init($param);
	}
}