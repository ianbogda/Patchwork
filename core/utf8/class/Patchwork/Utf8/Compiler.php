<?php /****************** vi: set fenc=utf-8 ts=4 sw=4 et: *****************
 *
 *   Copyright : (C) 2012 Nicolas Grekas. All rights reserved.
 *   Email     : p@tchwork.org
 *   License   : http://www.gnu.org/licenses/lgpl.txt GNU/LGPL
 *
 *   This library is free software; you can redistribute it and/or
 *   modify it under the terms of the GNU Lesser General Public
 *   License as published by the Free Software Foundation; either
 *   version 3 of the License, or (at your option) any later version.
 *
 ***************************************************************************/

namespace Patchwork\Utf8;

/**
 * Compiler is a use once class that implements the compilation of unicode
 * and charset data to a format suitable for other Utf8 classes.
 *
 * See http://unicode.org/Public/UNIDATA/ for unicode data
 * See http://unicode.org/Public/MAPPINGS/ for charset conversion maps
 * See http://unicode.org/Public/MAPPINGS/VENDORS/MICSFT/WindowsBestFit/ for mappings
 * See http://www.gnu.org/software/libiconv/ for translit.def
 */
class Compiler
{
    static function charsetMaps($out_dir, $map_dir = null)
    {
        isset($map_dir) || $map_dir = __DIR__ . '/unicode/charset/';
        $h = opendir($map_dir);
        while (false !== $f = readdir($h)) if (false === strpos($f, '.') && is_file($map_dir . $f))
        {
            $from = $to = array();
            $data = file_get_contents($map_dir . $f);

            if ('gsm0338' === $f) $rx = '/^#?0x([0-9A-F]+)[ \t]+0x([0-9A-F]+)/mi';
            else $rx = '/^0x([0-9A-F]+)[ \t]+0x([0-9A-F]+)/mi';

            preg_match_all($rx, $data, $data, PREG_SET_ORDER);

            if ('nextstep' === $f)
            {
                $from = array_map('chr', range(0, 127));
                $from = array_combine($from, $from);
            }
            else if ('mazovia' === $f)
            {
                $from = array("\x9B" => self::chr(0x007A) . self::chr(0x0142));
                $to = array(self::chr(0x203A) => "\x9B");
            }

            foreach ($data as $data)
            {
                $data = array_map('hexdec', $data);
                $data[2] = self::chr($data[2]);
                $data[1] = $data[1] > 255
                    ? chr($data[1]>>8) . chr($data[1]%256)
                    : chr($data[1]);

                if (isset($from[$data[1]]))
                {
                    isset($to[$data[2]]) or $to[$data[2]] = $data[1];
                }
                else
                {
                    $from[$data[1]] = $data[2];
                }
            }

            file_put_contents("{$out_dir}from.{$f}.ser", serialize($from));

            if ($to)
            {
                $to += array_flip($from);
                file_put_contents("{$out_dir}to.{$f}.ser", serialize($to));
            }
        }
        closedir($h);
    }

    static function translitMap($out_dir, $translit_def = null)
    {
        isset($translit_def) || $translit_def = __DIR__ . '/unicode/charset/translit.def';
        $data = file_get_contents($translit_def);
        preg_match_all('/^([0-9A-F]+)\t([^\t]+)\t/mi', $data, $data, PREG_SET_ORDER);

        $map = array();
        foreach ($data as $data) $map[self::chr(hexdec($data[1]))] = $data[2];

        file_put_contents($out_dir . 'translit.ser', serialize($map));
    }

    static function bestFit($out_dir, $map_dir = null)
    {
        isset($map_dir) || $map_dir = __DIR__ . '/unicode/charset/';
        $dh = opendir($map_dir);
        while (false !== $f = readdir($dh)) if (0 === strpos($f, 'bestfit') && preg_match('/^bestfit\d+\.txt$/D', $f))
        {
            $from = array();
            $to = array();
            $lead = '';

            $h = fopen($map_dir . $f, 'rb');

            while (false !== $s = fgets($h))
            {
                if (0 === strpos($s, 'WCTABLE')) break;
                if (0 === strpos($s, 'DBCSTABLE'))
                {
                    $lead = substr(rtrim($s), -2);
                    $lead = chr(hexdec($lead));
                }
                else if (preg_match("/^0x([0-9a-f]{2})\t0x([0-9a-f]{4})/", $s, $s))
                {
                    $s = array_map('hexdec', $s);
                    $from[$lead . chr($s[1])] = self::chr($s[2]);
                }
            }

            while (false !== $s = fgets($h))
            {
                if (0 === strpos($s, 'ENDCODEPAGE')) break;

                $s = explode("\t", rtrim($s));

                if (isset($s[1]))
                {
                    $s[0] = substr($s[0], 2);
                    $s[1] = substr($s[1], 2);
                    $s = array_map('hexdec', $s);
                    $s[1] = $s[1] > 255
                        ? chr($s[1]>>8) . chr($s[1]%256)
                        : chr($s[1]);

                    $to[self::chr($s[0])] = $s[1];
                }
            }

            fclose($h);

            file_put_contents($out_dir . 'from.' . substr($f, 0, -3) .'ser', serialize($from));
            file_put_contents($out_dir . 'to.' . substr($f, 0, -3) .'ser', serialize($to));
        }
        closedir($dh);
    }

    // Write unicode data maps to disk

    static function unicodeMaps($out_dir)
    {
        $upperCase = array();
        $lowerCase = array();
        $caseFolding = array();
        $combiningClass = array();
        $canonicalComposition = array();
        $canonicalDecomposition = array();
        $compatibilityDecomposition = array();


        $exclusion = array();

        $h = fopen(self::getFile('CompositionExclusions.txt'), 'rt');
        while (false !== $m = fgets($h))
        {
            if (preg_match('/^(?:# )?([0-9A-F]+) /', $m, $m))
            {
                $exclusion[self::chr(hexdec($m[1]))] = 1;
            }
        }
        fclose($h);


        $h = fopen(self::getFile('UnicodeData.txt'), 'rt');
        while (false !== $m = fgets($h))
        {
            $m = explode(';', $m);

            $k = self::chr(hexdec($m[0]));
            $combClass = (int) $m[3];
            $decomp = $m[5];

            $m[12] && $m[12]!=$m[0] && $upperCase[$k] = self::chr(hexdec($m[12]));
            $m[13] && $m[13]!=$m[0] && $lowerCase[$k] = self::chr(hexdec($m[13]));

            $combClass && $combiningClass[$k] = $combClass;

            if ($decomp)
            {
                $canonic = '<' != $decomp[0];
                $canonic || $decomp = preg_replace("'^<.*> '", '', $decomp);

                $decomp = explode(' ', $decomp);

                $exclude = count($decomp) == 1 || isset($exclusion[$k]);

                $decomp = array_map('hexdec', $decomp);
                $decomp = array_map(array(__CLASS__, 'chr'), $decomp);
                $decomp = implode('', $decomp);

                if ($canonic)
                {
                    $canonicalDecomposition[$k] = $decomp;
                    $exclude || $canonicalComposition[$decomp] = $k;
                }

                $compatibilityDecomposition[$k] = $decomp;
            }
        }
        fclose($h);

        do
        {
            $m = 0;

            foreach($canonicalDecomposition as $k => $decomp)
            {
                $h = strtr($decomp, $canonicalDecomposition);
                if ($h != $decomp)
                {
                    $canonicalDecomposition[$k] = $h;
                    $m = 1;
                }
            }
        }
        while ($m);

        do
        {
            $m = 0;

            foreach($compatibilityDecomposition as $k => $decomp)
            {
                $h = strtr($decomp, $compatibilityDecomposition);
                if ($h != $decomp)
                {
                    $compatibilityDecomposition[$k] = $h;
                    $m = 1;
                }
            }
        }
        while ($m);

        foreach($compatibilityDecomposition as $k => $decomp)
        {
            if (isset($canonicalDecomposition[$k]) && $canonicalDecomposition[$k] == $decomp) unset($compatibilityDecomposition[$k]);
        }


        $h = fopen(self::getFile('CaseFolding.txt'), 'rt');
        while (false !== $m = fgets($h))
        {
            if (preg_match('/^([0-9A-F]+); ([CFST]); ([0-9A-F]+(?: [0-9A-F]+)*)/', $m, $m))
            {
                $k = self::chr(hexdec($m[1]));

                $decomp = explode(' ', $m[3]);
                $decomp = array_map('hexdec', $decomp);
                $decomp = array_map(array(__CLASS__, 'chr'), $decomp);
                $decomp = implode('', $decomp);

                @($lowerCase[$k] != $decomp && $caseFolding[$m[2]][$k] = $decomp);
            }
        }
        fclose($h);

        // Only full case folding is worth serializing
        $caseFolding = array(
            array_keys(  $caseFolding['F']),
            array_values($caseFolding['F'])
        );

        $upperCase = serialize($upperCase);
        $lowerCase = serialize($lowerCase);
        $caseFolding = serialize($caseFolding);
        $combiningClass = serialize($combiningClass);
        $canonicalComposition = serialize($canonicalComposition);
        $canonicalDecomposition = serialize($canonicalDecomposition);
        $compatibilityDecomposition = serialize($compatibilityDecomposition);

        file_put_contents($out_dir . 'upperCase.ser', $upperCase);
        file_put_contents($out_dir . 'lowerCase.ser', $lowerCase);
        file_put_contents($out_dir . 'caseFolding_full.ser', $caseFolding);
        file_put_contents($out_dir . 'combiningClass.ser', $combiningClass);
        file_put_contents($out_dir . 'canonicalComposition.ser', $canonicalComposition);
        file_put_contents($out_dir . 'canonicalDecomposition.ser', $canonicalDecomposition);
        file_put_contents($out_dir . 'compatibilityDecomposition.ser', $compatibilityDecomposition);
    }

    protected static function optimizeRx($rx)
    {
        $rx = preg_replace_callback('/\\\\x\\{([0-9A-Fa-f]+)\\}-\\\\x\\{([0-9A-Fa-f]+)\\}/', array(__CLASS__, 'chr_range_callback'), $rx);

        preg_match_all('/[0-9A-Fa-f]+/', $rx, $rx);

        $rx = array_map('hexdec', $rx[0]);
        $rx = array_unique($rx);
        sort($rx);

        $a = '';
        $last = 0;
        $interval = 0;

        foreach ($rx as $rx)
        {
            if ($last+1 == $rx)
            {
                ++$last;
                ++$interval;
            }
            else
            {
                $interval && $a .= ($interval > 1 ? '-' : '') . '\x{' . dechex($last) . '}';

                $last = $rx;
                $interval = 0;

                $a .= '\x{' . dechex($rx) . '}';
            }
        }

        $interval && $a .= ($interval > 1 ? '-' : '') . '\x{' . dechex($last) . '}';

        return $a;
    }

    protected static function chr_callback($m) {return self::chr(hexdec($m[1]));}
    protected static function chr_range_callback($m) {return '\x{' . implode('}\x{', array_map('dechex', range(hexdec($m[1]), hexdec($m[2])))) . '}';}

    protected static function chr($c)
    {
        $c %= 0x200000;

        return $c < 0x80    ? chr($c) : (
               $c < 0x800   ? chr(0xC0 | $c>> 6) . chr(0x80 | $c     & 0x3F) : (
               $c < 0x10000 ? chr(0xE0 | $c>>12) . chr(0x80 | $c>> 6 & 0x3F) . chr(0x80 | $c    & 0x3F) : (
                              chr(0xF0 | $c>>18) . chr(0x80 | $c>>12 & 0x3F) . chr(0x80 | $c>>6 & 0x3F) . chr(0x80 | $c & 0x3F)
        )));
    }

    protected static function getFile($file)
    {
        return __DIR__ . '/unicode/data/' . $file;
    }

    protected static function cmpByLength($a, $b)
    {
        return strlen($b) - strlen($a);
    }
}
