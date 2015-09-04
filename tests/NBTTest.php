<?php
use NBT\NBT;

class NBTTest extends PHPUnit_Framework_TestCase {

    protected $nbt;

    protected function setUp() {
        $this->nbt = new NBT();
        $this->nbt->setDebug(true);
    }

    public function testLoadFile() {
        $this->nbt->loadFile('tests/smalltest.nbt');

        $this->assertNotEmpty($this->nbt->root);
    }

    public function testNBTTypes() {
        $this->nbt->loadFile('tests/bigtest.nbt');

        $root = $this->nbt->root[0];
        $values = $root['value'];

        // Long
        $this->assertEquals(4, $values[0]['type']);
        $this->assertEquals('longTest', $values[0]['name']);
        $this->assertEquals('9223372036854775807', $values[0]['value']);

        // Short
        $this->assertEquals(2, $values[1]['type']);
        $this->assertEquals('shortTest', $values[1]['name']);
        $this->assertEquals(32767, $values[1]['value']);

        // String
        $this->assertEquals(8, $values[2]['type']);
        $this->assertEquals('stringTest', $values[2]['name']);
        $this->assertEquals('HELLO WORLD THIS IS A TEST STRING ' . chr(197) . chr(196) . chr(214) .'!', $values[2]['value']);

        // Float
        $this->assertEquals(5, $values[3]['type']);
        $this->assertEquals('floatTest', $values[3]['name']);
        $this->assertEquals(0.4982315, $values[3]['value'], '', 0.0000001);

        // Integer
        $this->assertEquals(3, $values[4]['type']);
        $this->assertEquals('intTest', $values[4]['name']);
        $this->assertEquals(2147483647, $values[4]['value']);

        // Compound
        $this->assertEquals(10, $values[5]['type']);
        $this->assertEquals('nested compound test', $values[5]['name']);
        $this->assertCount(2, $values[5]['value'][1]['value']);

        // Byte
        $this->assertEquals(1, $values[8]['type']);
        $this->assertEquals('byteTest', $values[8]['name']);
        $this->assertEquals(127, $values[8]['value']);

        // Double
        $this->assertEquals(6, $values[10]['type']);
        $this->assertEquals('doubleTest', $values[10]['name']);
        $this->assertEquals(0.493128713218231, $values[10]['value']);
    }


    public function testWriteFile() {
        $this->nbt->loadFile('tests/smalltest.nbt');
        $this->nbt->loadFile('tests/bigtest.nbt');

        $tmp = tempnam(sys_get_temp_dir(), 'nbt');
        $this->nbt->writeFile($tmp);

        $this->assertNotFalse($tmp, 'Temp file could not be created.');
        $this->assertFileExists($tmp);
    }

}
