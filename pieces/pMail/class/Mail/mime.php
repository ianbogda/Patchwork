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


class extends self
{
	// The original Mail_mime->_encodeHeaders() is bugged !

	function _encodeHeaders($input)
	{
		$ns = "[^\(\)<>@,;:\"\/\[\]\r\n]*";

		foreach ($input as &$hdr_value)
		{
			$this->optimizeCharset($hdr_value, 'head');
			$hdr_value = preg_replace_callback(
				"/{$ns}(?:[\\x80-\\xFF]{$ns})+/",
				array($this, '_encodeHeaderWord'),
				$hdr_value
			);
		}

		return $input;
	}

	protected function _encodeHeaderWord($word)
	{
		$word = preg_replace('/[=_\?\x00-\x1F\x80-\xFF]/e', '"=".strtoupper(dechex(ord("\0")))', $word[0]);

		preg_match('/^( *)(.*?)( *)$/', $word, $w);

		$word =& $w[2];
		$word = str_replace(' ', '_', $word);

		$start = '=?' . $this->_build_params['head_charset'] . '?Q?';
		$offsetLen = strlen($start) + 2;

		$w[1] .= $start;

		while ($offsetLen + strlen($word) > 75)
		{
			$splitPos = 75 - $offsetLen;

			switch ('=')
			{
				case substr($word, $splitPos - 2, 1): --$splitPos;
				case substr($word, $splitPos - 1, 1): --$splitPos;
			}

			$w[1] .= substr($word, 0, $splitPos) . "?={$this->_eol} {$start}";
			$word = substr($word, $splitPos);
		}

		return $w[1] . $word . '?=' . $w[3];
	}


	// Add line feeds correction

	function setTXTBody($data, $isfile = false, $append = false)
	{
		$isfile || $this->_fixEOL($data);
		$this->optimizeCharset($data, 'text');
		return parent::setTXTBody($data, $isfile, $append);
	}

	function setHTMLBody($data, $isfile = false)
	{
		$isfile || $this->_fixEOL($data);

		$data = preg_replace('#<(head|script|title|applet|frameset|i?frame)\b[^>]*>.*?</\1\b[^>]*>#is', '', $data);
		$data = preg_replace('#</?(?:!DOCTYPE|html|meta|body|base|link)\b[^>]*>#is', '', $data);
		$data = preg_replace('#<!--.*?-->#s', '', $data);
		$data = trim($data);

		$this->optimizeCharset($data, 'html');
		return parent::setHTMLBody($data, $isfile);
	}

	function &_file2str($file_name)
	{
		$file_name =& parent::_file2str($file_name);
		$this->fixEOL($file_name);
		return $file_name;
	}

	protected function _fixEOL(&$a)
	{
		false !== strpos($a, "\r") && $a = strtr(str_replace("\r\n", "\n", $a), "\r", "\n");
		"\n"  !== $this->_eol      && $a = str_replace("\n", $this->_eol, $a);
	}

	protected static $charsetCheck = array(
		'windows-1252' => '1,cp1252',
		'iso-8859-1'   => '1,iso8859-1,latin1',
		'iso-8859-2'   => '1,iso8859-2,latin2',
		'iso-8859-3'   => '1,iso8859-3,latin3',
		'iso-8859-4'   => '1,iso8859-4,latin4',
		'iso-8859-5'   => '0,iso8859-5',
		'iso-8859-6'   => '0,iso8859-6',
		'iso-8859-7'   => '0,iso8859-7',
		'iso-8859-8'   => '0,iso8859-8',
		'iso-8859-9'   => '1,iso8859-9,latin5',
		'iso-8859-10'  => '1,iso8859-10,latin6',
		'iso-8859-11'  => '0,iso8859-11',
		'iso-8859-13'  => '1,iso8859-13,latin7',
		'iso-8859-14'  => '1,iso8859-14,latin8',
		'iso-8859-15'  => '1,iso8859-15,latin9',
		'iso-8859-16'  => '1,iso8859-16,latin10',
		'viscii'       => 1,
		'big5'         => 0,
		'iso-2022-jp'  => 0,
		'koi8-r'       => '0,koi8',
	);

	protected function optimizeCharset(&$data, $type)
	{
		// In an ideal world, every email client would handle UTF-8...

		if (function_exists('iconv')) foreach (self::$charsetCheck as $charset => $enc)
		{
			$c = $charset;
			$a = @iconv('UTF-8', $c, $data);

			if (false === $a && is_string($c))
			{
				$c = explode(',', $c);
				unset($c[0]);
				foreach ($c as $c)
				{
					$b = @iconv('UTF-8', $c, $data);
					if (false !== $b)
					{
						$a =& $b;
						break;
					}
				}
			}

			if (false !== $a && iconv($c, 'UTF-8', $conv) === $data)
			{
				$data =& $a;
				$enc = (int) $enc ? 'quoted-printable' : 'base64';

				$this->_build_params[$type . '_charset' ] = $charset;
				$this->_build_params[$type . '_encoding'] = $enc;

				return;
			}
		}

		$this->_build_params[$type . '_charset' ] = 'UTF-8';
		$this->_build_params[$type . '_encoding'] = 'base64';
	}
}
