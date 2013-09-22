<?php

namespace Patchwork\Tests\PHP;

use Patchwork\PHP\Parser;

class BinaryNumberTest extends \PHPUnit_Framework_TestCase
{
    protected function getParser()
    {
        $p = new Parser\BinaryNumber;
        $p->targetPhpVersionId = 50300;
        return $p;
    }

    function testParse()
    {
        $parser = $this->getParser();

        $in = <<<EOPHP
<?php
0b01010101010;
0B01010101010;
EOPHP;

        $out = <<<EOPHP
<?php
0x2AA;
0x2AA;
EOPHP;

        $this->assertSame( $out, $parser->parse($in) );
    }
}
