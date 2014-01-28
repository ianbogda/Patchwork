<?php

namespace Patchwork\Tests\PHP;

use Patchwork\PHP\Parser;

class NormalizerTest extends \PHPUnit_Framework_TestCase
{
    protected function getParser($dump = false)
    {
        $p = $dump ? new Parser\Dumper : new Parser;
        $p = new Parser\Normalizer($p);

        return $p;
    }

    function testLineEndings()
    {
        $parser = $this->getParser();

        $in = "<?php\r\n\n\r";
        $out = "<?php\n\n\n";

        $this->assertSame( $out, $parser->parse($in) );
        $this->assertSame( array(), $parser->getErrors() );
    }

    function testUtf8()
    {
        $parser = $this->getParser();

        $in = "<?php \xE9";
        $out = "<?php \xE9";

        $this->assertSame( $out, $parser->parse($in) );

        $this->assertSame(
            array(
                array(
                    'type' => E_USER_WARNING,
                    'message' => 'File encoding is not valid UTF-8',
                    'line' => 1,
                    'parser' => 'Patchwork\PHP\Parser\Normalizer',
                ),
            ),
            $parser->getErrors()
        );
    }

    function testBom()
    {
        $parser = $this->getParser();

        $in = "\xEF\xBB\xBF<?php ";
        $out = "<?php ";

        $this->assertSame( $out, $parser->parse($in) );
        $this->assertSame(
            array(
                array(
                    'type' => E_USER_NOTICE,
                    'message' => 'Stripping UTF-8 Byte Order Mark',
                    'line' => 0,
                    'parser' => 'Patchwork\PHP\Parser\Normalizer',
                ),
            ),
            $parser->getErrors()
        );
    }

    function testOpenTag()
    {
        $parser = $this->getParser();

        $in = "<html><?php ";
        $out = "<?php ?><html><?php ";

        $this->assertSame( $out, $parser->parse($in) );
        $this->assertSame( array(), $parser->getErrors() );

        $parser = $this->getParser();

        $in = "\n<html><?php ";
        $out = "<?php echo\"\\n\"?>\n<html><?php ";

        $this->assertSame( $out, $parser->parse($in) );
        $this->assertSame( array(), $parser->getErrors() );
    }

    function testOpenEcho()
    {
        $parser = $this->getParser();

        $in = "<?=A;";
        $out = "<?php echo A;";

        $this->assertSame( $out, $parser->parse($in) );
        $this->assertSame( array(), $parser->getErrors() );
    }

    function testFixVar()
    {
        $parser = $this->getParser();

        $in  = '<?php class {var $a;}';
        $out = '<?php class {public $a;}';

        $this->assertSame( $out, $parser->parse($in) );
        $this->assertSame( array(), $parser->getErrors() );
    }

    function testEndPhp()
    {
        $parser = $this->getParser();

        $in  = '<?php __halt_compiler()?>';
        $out = '<?php ;__halt_compiler();';

        $this->assertSame( $out, $parser->parse($in) );
        $this->assertSame( array(), $parser->getErrors() );
    }
}
