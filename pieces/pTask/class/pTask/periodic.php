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


class extends pTask
{
	static

	$days   = array('sun'=>0,'mon'=>1,'thu'=>2,'wed'=>3,'tue'=>4,'fri'=>5,'sat'=>6),
	$months = array('jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12);


	protected

	$crontab = array(),
	$finalRun = 0,
	$runLimit = -1;


	function __construct($callback = false, $arguments = array())
	{
		is_string($this->crontab) && $this->setCrontab($this->crontab);
		parent::__construct($callback, $arguments);
	}

	function setCrontab($crontab)
	{
		is_array($crontab) && $crontab = implode("\n", $crontab);
		$crontab = strtolower(trim($crontab));
		false !== strpos($crontab, "\r")  && $crontab = strtr(str_replace("\r\n", "\n", $crontab), "\r", "\n");

		$c = explode("\n", $crontab);
		$crontab = array();

		foreach ($c as &$cronline)
		{
			$cronline = trim($cronline);
			$cronline = preg_split('/\s+/', $cronline);

			if ('' === $cronline[0]) continue;

			$i = 5;
			while (!isset($cronline[--$i])) $cronline[$i] = '*';

			$cronline[3] = strtr($cronline[3], self::$months);
			$cronline[4] = strtr($cronline[4], self::$days  );

			$cronline[0] = self::expandCrontabItem($cronline[0], 0, 59);
			$cronline[1] = self::expandCrontabItem($cronline[1], 0, 23);
			$cronline[2] = self::expandCrontabItem($cronline[2], 1, 31);
			$cronline[3] = self::expandCrontabItem($cronline[3], 1, 12);
			$cronline[4] = self::expandCrontabItem($cronline[4], 0, 6 );

			$crontab[] =& $cronline;
		}

		$this->crontab =& $crontab;

		return $this;
	}

	function setFinalRun($time)
	{
		$this->finalRun = (int) $time;
		return $this;
	}

	function setRunLimit($count)
	{
		$this->runLimit = (int) $count;
		return $this;
	}


	function getNextRun($time = false)
	{
		if (!$this->runLimit || !$this->crontab
			|| (0 < $this->finalRun && $this->finalRun <= $_SERVER['REQUEST_TIME']))
		{
			return 0;
		}

		$this->runLimit > 0 && --$this->runLimit;

		$time = getdate(false !== $time ? $time : $_SERVER['REQUEST_TIME']);

		$nextRun = 0;

		foreach ($this->crontab as &$cronline)
		{
			$next = array($time['minutes'], $time['hours'], $time['mday'], $time['mon'], $time['year']);

			switch (true)
			{
			case !in_array($next[3], $cronline[3]): $next[2] = 1;
			case !in_array($next[2], $cronline[2]): $next[1] = 0;
			case !in_array($next[1], $cronline[1]): $next[0] = 0; break;
			case  in_array($next[0], $cronline[0]): ++$next[0];
			}

			for ($n = 0; $n < 4; ++$n) self::putNextTick($next, $cronline, $n);

			while (($n = mktime($next[1], $next[0], 0, $next[3], $next[2], $next[4]))
				&& (!$nextRun || $n < $nextRun)
				&& !in_array(idate('w', $n), $cronline[4]))
			{
				++$next[2];
				$next[0] = $cronline[0][0];
				$next[1] = $cronline[1][0];
				self::putNextTick($next, $cronline, 2);
				self::putNextTick($next, $cronline, 3);
			}

			if (!$nextRun || $n < $nextRun) $nextRun = $n;
		}

		return $nextRun;
	}


	protected static function expandCrontabItem($cronitem, $min, $max)
	{
		if ('*' == $cronitem[0]) $cronitem = $min . '-' . $max . substr($cronitem, 1);

		$width = $max - $min + 1;
		$cronitem = explode(',', $cronitem);

		$list = array();

		foreach ($cronitem as $i)
		{
			if (preg_match('#^(\d+)(?:-(\d+)((?:[~/]\d+)*))?$#', $i, $item))
			{
				$item[1] = ($item[1] - $min) % $width + $min;

				if (isset($item[2]))
				{
					$item[2] = ($item[2] - $min) % $width + $min;
					if ($item[2] < $item[1]) $item[2] += $width;

					$range = range($item[1], $item[2]);
					foreach ($range as &$i) $i = ($i - $min) % $width + $min;
					unset($i);

					if (isset($item[3]))
					{
						$item = preg_split('#([~/])#', $item[3], -1, PREG_SPLIT_DELIM_CAPTURE);
						$len = count($item);
						for ($i = 2; $i < $len; $i+=2)
						{
							if ('~' == $item[$i-1])
							{
								$item[$i] = ($item[$i] - $min) % $width + $min;
								$range = array_diff($range, array($item[$i]));
							}
							else
							{
								$item[$i] = (int) $item[$i];

								$range2 = array();
								for ($j = 0; isset($range[$j]); $j += $item[$i]) $range2[] = $range[$j];
								$range = $range2;
							}
						}
					}

					$range || $range = range($min, $max);

					$list = array_merge($list, $range);
				}
				else $list[] = $item[1];
			}
			else W("Invalid crontab item: " . $i);
		}

		$list = array_unique($list);
		sort($list);

		return $list;
	}

	protected static function putNextTick(&$next, &$list, $index)
	{
		$list =& $list[$index];

		$len = count($list);
		for ($i = 0; $i < $len && $list[$i] < $next[$index]; ++$i) {}

		if ($i == $len)
		{
			$i = 0;
			++$next[$index + 1];
			$next = getdate(mktime($next[1], $next[0], 0, $next[3], $next[2], $next[4]));
			$next = array($next['minutes'], $next['hours'], $next['mday'], $next['mon'], $next['year']);
		}

		$next[$index] = $list[$i];
	}
}