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


class extends pTask
{
	protected $test_mode = false;

	static function send($headers, $body, $options = null)
	{
		return self::pushMail(array(
			'headers' => &$headers,
			'body' => &$body,
			'options' => &$options,
		));
	}

	static function sendAgent($headers, $agent, $args = array(), $options = null)
	{
		return self::pushMail(array(
			'headers' => &$headers,
			'agent' => &$agent,
			'args' => &$args,
			'options' => &$options,
		));
	}

	protected static function pushMail($data)
	{
		$queue = new self;

		if ($queue->test_mode)
		{
			$data['headers']['X-Original-To'] = $data['headers']['To'];
			$data['headers']['To'] = $CONFIG['pMail.debug_email'];
		}

		if (!isset($data['headers']['From']) && $CONFIG['pMail.from']) $data['headers']['From'] = $CONFIG['pMail.from'];
		if (isset($data['headers']['From']) && !$data['headers']['From']) W("Email is likely not to be sent: From header is empty.");

		$data['session'] = isset($_COOKIE['SID']) ? SESSION::getAll() : array();


		$sqlite = $queue->getSqlite();

		$sent = - (int)(bool) $queue->test_mode;
		$archive = (int) ((isset($data['options']['archive']) && $data['options']['archive']) || $queue->test_mode);

		$time = isset($data['options']['time']) ? $data['options']['time'] : 0;
		if ($time < $_SERVER['REQUEST_TIME'] - 366*86400) $time += $_SERVER['REQUEST_TIME'];

		$base = sqlite_escape_string(p::__BASE__());
		$data = sqlite_escape_string(serialize($data));

		$sql = "INSERT INTO queue VALUES('{$base}','{$data}',{$time},{$archive},{$sent})";
		$sqlite->queryExec($sql);

		$id = $sqlite->lastInsertRowid();

		self::$is_registered || $queue->registerQueue();

		return $id;
	}

	static function schedule(self $task, $time = 0)
	{
		throw new Exception(__CLASS__ . '::schedule() is disabled');
	}


	protected function defineQueue()
	{
		parent::defineQueue();

		$this->queueFolder = 'data/queue/pMail/';
		$this->queueUrl = 'queue/pMail';
		$this->queueSql = '
			CREATE TABLE queue (base TEXT, data BLOB, send_time INTEGER, archive INTEGER, sent_time INTEGER);
			CREATE INDEX send_time ON queue (send_time);
			CREATE INDEX sent_time ON queue (sent_time);
			CREATE VIEW waiting AS SELECT * FROM queue WHERE send_time>0 AND sent_time=0;
			CREATE VIEW error   AS SELECT * FROM queue WHERE send_time=0;
			CREATE VIEW archive AS SELECT * FROM queue WHERE sent_time>0;';
	}
}