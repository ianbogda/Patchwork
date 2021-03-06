<?php // vi: set fenc=utf-8 ts=4 sw=4 et:
/*
 * Copyright (C) 2012 Nicolas Grekas - p@tchwork.com
 *
 * This library is free software; you can redistribute it and/or modify it
 * under the terms of the (at your option):
 * Apache License v2.0 (http://apache.org/licenses/LICENSE-2.0.txt), or
 * GNU General Public License v2.0 (http://gnu.org/licenses/gpl-2.0.txt).
 */

namespace Patchwork\PHP;

/**
 * Logger logs messages to an output stream.
 *
 * Messages just have a type and associated data. The dump format is handled by JsonDumper
 * which allows unprecedented accuracy for associated data representation.
 *
 * Error messages are handled specifically in order to make them more friendly,
 * especially for traces and exceptions.
 */
class Logger
{
    const META_PREFIX = "\0~\0";

    public

    $lineFormat = "%s",
    $loggedGlobals = array('_SERVER');

    protected

    $uniqId,
    $logStream,
    $prevTime = 0,
    $startTime = 0,
    $isFirstEvent = true;

    public static

    $errorTypes = array(
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_STRICT => 'E_STRICT',
    );


    function __construct($log_stream, $start_time = 0)
    {
        $this->uniqId = mt_rand();
        $start_time || $start_time = microtime(true);
        $this->startTime = $this->prevTime = $start_time;
        $this->logStream = $log_stream;
    }

    function log($type, $data, $log_time = 0)
    {
        // Get time and memory profiling information

        $log_time || $log_time = microtime(true);

        $data = array(
            'time' => date('c', $log_time) . sprintf(
                ' %06dus - %0.3fms - %0.3fms',
                100000 * ($log_time - floor($log_time)),
                  1000 * ($log_time - $this->startTime),
                  1000 * ($log_time - $this->prevTime)
            ),
            'mem'  => memory_get_peak_usage() . ' - ' . memory_get_usage(),
            'data' => $data,
        );

        if ($this->isFirstEvent && $this->loggedGlobals)
        {
            $data['globals'] = array();
            foreach ($this->loggedGlobals as $log_time)
                $data['globals'][$log_time] = isset($GLOBALS[$log_time]) ? $GLOBALS[$log_time] : null;
        }

        $this->writeEvent($type, $data);

        $this->prevTime = microtime(true);
        $this->isFirstEvent = false;
    }

    function logError($e, $trace_offset = -1, $trace_args = 0, $log_time = 0)
    {
        $e = array(
            'mesg' => $e['message'],
            'type' => self::$errorTypes[$e['type']] . ' ' . $e['file'] . ':' . $e['line'],
        ) + $e;

        unset($e['message'], $e['file'], $e['line']);
        if (0 > $trace_offset) unset($e['trace']);
        else if (!empty($e['trace'])) $this->filterTrace($e['trace'], $trace_offset, $trace_args);

        $this->log('php-error', $e, $log_time);
    }

    function castException($e)
    {
        $a = (array) $e;

        $trace = $a["\0Exception\0trace"];
        unset($a["\0Exception\0trace"]); // Ensures the trace is always last

        if (isset($trace[0]))
        {
            if (isset($trace[0][$this->uniqId]))
            {
                $a["\0Exception\0trace"] = array('seeHash' => spl_object_hash($e));
            }
            else
            {
                static $traceProp;

                if (! isset($traceProp))
                {
                    $traceProp = new \ReflectionProperty('Exception', 'trace');
                    $traceProp->setAccessible(true);
                }

                $trace[0][$this->uniqId] = 1;
                $traceProp->setValue($e, $trace);

                $this->filterTrace($trace, $e instanceof InDepthRecoverableErrorException ? $e->traceOffset : 0, 1);

                if (isset($trace)) $a["\0Exception\0trace"] = $trace;

                $a[self::META_PREFIX . 'hash'] = spl_object_hash($e);
            }
        }

        if ($e instanceof InDepthRecoverableErrorException)
        {
            unset($a['traceOffset']);

            if (null === $a['context']) unset($a['context']);
            else if (isset($a["\0Exception\0trace"]['seeHash']))
            {
                $a['context'] = $a["\0Exception\0trace"];
            }
        }

        if (empty($a["\0Exception\0previous"])) unset($a["\0Exception\0previous"]);
        if ($e instanceof \ErrorException && isset(self::$errorTypes[$a["\0*\0severity"]])) $a["\0*\0severity"] = self::$errorTypes[$a["\0*\0severity"]];
        unset($a["\0Exception\0string"], $a['xdebug_message'], $a['__destructorException']);

        return $a;
    }

    function filterTrace(&$trace, $offset, $args)
    {
        if (0 > $offset || empty($trace[$offset])) return $trace = null;

        $t = $trace[$offset];

        if (empty($t['class']) && isset($t['function']))
            if ('user_error' === $t['function'] || 'trigger_error' === $t['function'])
                ++$offset;

        $offset && array_splice($trace, 0, $offset);

        foreach ($trace as &$t)
        {
            $offset = (isset($t['class']) ? $t['class'] . $t['type'] : '')
                . $t['function'] . '()'
                . (isset($t['line']) ? " {$t['file']}:{$t['line']}" : '');

            if (! isset($t['args']) || ! $args) $t = array();
            else $t = array('args' => $t['args']);

            $t = array('call' => $offset) + $t;
        }
    }

    function writeEvent($type, $data)
    {
        fprintf($this->logStream, $this->lineFormat . PHP_EOL, "*** {$type} ***");

        $d = new JsonDumper;
        $d->setCallback('line', array($this, 'writeLine'));
        $d->setCallback('o:exception', array($this, 'castException'));
        $d->walk($data);

        fprintf($this->logStream, $this->lineFormat . PHP_EOL, '***');
    }

    function writeLine($line, $depth)
    {
        fprintf($this->logStream, $this->lineFormat . PHP_EOL, str_repeat('  ', $depth) . $line);
    }
}
