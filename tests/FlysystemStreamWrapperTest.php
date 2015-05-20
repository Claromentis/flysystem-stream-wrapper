<?php

namespace Twistor\Tests;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Adapter\NullAdapter;
use League\Flysystem\Filesystem;
use Twistor\FlysystemStreamWrapper;

class FlysystemStreamWrapperTest extends \PHPUnit_Framework_TestCase
{
    public function testRegister()
    {
        $filesystem = new Filesystem(new NullAdapter());
        $this->assertTrue(FlysystemStreamWrapper::register('test', $filesystem));

        $this->assertTrue(in_array('test', stream_get_wrappers(), true));

        // Registering twice should be a noop.
        $this->assertFalse(FlysystemStreamWrapper::register('test', $filesystem));

        $this->assertTrue(FlysystemStreamWrapper::unregister('test'));
        $this->assertFalse(FlysystemStreamWrapper::unregister('test'));
    }

    /**
     * @expectedException \Exception
     */
    public function testTriggerError()
    {
        $wrapper = new FlysystemStreamWrapper();

        $method = new \ReflectionMethod($wrapper, 'triggerError');
        $method->setAccessible(TRUE);
        $method->invokeArgs($wrapper, ['function', [], new \Exception()]);
    }

    public function testHandleIsWritable()
    {
        $wrapper = new FlysystemStreamWrapper();

        $method = new \ReflectionMethod($wrapper, 'handleIsWritable');
        $method->setAccessible(TRUE);
        $this->assertFalse($method->invokeArgs($wrapper, [false]));
    }
}
