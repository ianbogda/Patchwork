<?php /*********************************************************************
 *
 *   Copyright : (C) 2006 Nicolas Grekas. All rights reserved.
 *   Email     : nicolas.grekas+patchwork@espci.org
 *   License   : http://www.gnu.org/licenses/gpl.txt GNU/GPL, see COPYING
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/


class extends Mail_mime
{
	protected $options;

	static function send($headers, $body, $options = null)
	{
		$mail = new iaMail_mime($options);

		$mail->headers($headers);
		$mail->setTxtBody($body);

		$mail->doSend();
	}

	static function sendAgent($headers, $agent, $argv = array(), $options = null)
	{
		$mail = new iaMail_agent($agent, $argv, $options);
		$mail->headers($headers);
		$mail->doSend();
	}


	protected function __construct($options = null)
	{
		parent::__construct(CIA_WINDOWS ? "\r\n" : "\n");

		$this->options = $options;

		$this->_build_params['text_encoding'] = 'quoted-printable';
		$this->_build_params['html_charset'] = 'UTF-8';
		$this->_build_params['text_charset'] = 'UTF-8';
		$this->_build_params['head_charset'] = 'UTF-8';
	}

	protected function doSend()
	{
		$message_id = 'iaM' . CIA::uniqid();

		$this->_headers['Message-Id'] = '<' . $message_id . '@' . (isset($_SERVER['HTTP_HOST']) ? urlencode($_SERVER['HTTP_HOST']) : 'iaMail') . '>';

		$this->setObserver('reply', 'Reply-To', $message_id);
		$this->setObserver('bounce', 'Return-Path', $message_id);

		$body =& $this->get($this->options);
		$headers =& $this->headers();

		if (isset($headers['From']))
		{
			if (!isset($headers['Reply-To'])   ) $headers['Reply-To'] = $headers['From'];
			if (!isset($headers['Return-Path'])) $headers['Return-Path'] = $headers['From'];
		}

		$to = $headers['To'];
		unset($headers['To']);

		$options = null;
		$backend = $GLOBALS['CONFIG']['email_backend'];

		switch ($backend)
		{
		case 'mail':
			$options = isset($GLOBALS['CONFIG']['email_options']) ? $GLOBALS['CONFIG']['email_options'] : '';
			if (isset($headers['Return-Path'])) $options .= ' -f ' . escapeshellarg($headers['Return-Path']);
			break;

		case 'smtp':
			$options = isset($GLOBALS['CONFIG']['email_options']) ? $GLOBALS['CONFIG']['email_options'] : array();
			break;
		}

		$mail = @Mail::factory($backend, $options);
		$mail->send($to, $headers, $body);
	}

	// The original _encodeHeaders of Mail_mime is bugged !
	function _encodeHeaders($input)
	{
		$ns = "[^\(\)<>@,;:\"\/\[\]\r\n]*";

		foreach ($input as &$hdr_value)
		{
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

	protected function setObserver($event, $header, $message_id)
	{
		if (!isset($this->options['on' . $event])) return;

		if (isset($this->options[$event . '_email'])) $email = $this->options[$event . '_email'];
		else if (isset($GLOBALS['CONFIG'][$event . '_email'])) $email = $GLOBALS['CONFIG'][$event . '_email'];

		if (isset($this->options[$event . '_url'])) $url = $this->options['reply_url'];
		else if (isset($GLOBALS['CONFIG'][$event . '_url'])) $url = $GLOBALS['CONFIG'][$event . '_url'];

		if (!isset($email)) E("{$event}_email has not been configured.");
		else if (!isset($url)) E("{$event}_url has not been configured.");
		else
		{
			$email = sprintf($email, $message_id);

			if (isset($this->headers[$header])) $this->headers[$header] .= ', ' . $email;
			else $this->headers[$header] = $email;

			if (ini_get('allow_url_fopen'))
			{
				$context = stream_context_create(array('http' => array(
					'method' => 'POST',
					'content' => http_build_query(array(
						'message_id' => $message_id,
						"{$event}_on{$event}" => CIA::home($this->options['on' . $event], true)
					))
				)));

				file_get_contents(CIA::home($url, true), false, $context);
			}
			else
			{
				$r = new HTTP_Request( CIA::home($url, true) );
				$r->setMethod(HTTP_REQUEST_METHOD_POST);
				$r->addPostData('message_id', $message_id);
				$r->addPostData("{$event}_on{$event}", CIA::home($this->options['on' . $event], true));
				$r->sendRequest();
			}
		}
	}

	// Add line feeds correction
	function setTXTBody($data, $isfile = false, $append = false)
	{
		if ($isfile)
		{
			$data =& $this->_file2str($data);
			if (@PEAR::isError($data)) return $data;
		}

		$data = str_replace("\n", $this->_eol, strtr(str_replace("\r\n", "\n", $data), "\r", "\n"));

		return parent::setTXTBody($data, false, $append);
	}

	// Add line feed correction
	function setHTMLBody($data, $isfile = false)
	{
		if ($isfile)
		{
			$data =& $this->_file2str($data);
			if (@PEAR::isError($data)) return $data;
		}

		$data = str_replace("\n", $this->_eol, strtr(str_replace("\r\n", "\n", $data), "\r", "\n"));

		return parent::setHTMLBody($data, false);
	}
}
