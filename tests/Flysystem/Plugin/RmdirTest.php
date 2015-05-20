<?php

namespace Twistor\Tests;

use League\Flysystem\Filesystem;
use Twistor\Flysystem\Plugin\Rmdir;

class RmdirTest extends \PHPUnit_Framework_TestCase
{
    public function test()
    {
        $plugin = new Rmdir();
        $adapter = $this->prophesize('League\Flysystem\AdapterInterface');
        $plugin->setFilesystem(new Filesystem($adapter->reveal()));

        $adapter->deleteDir('path')->willReturn(true);

        $this->assertTrue($plugin->handle('path', STREAM_MKDIR_RECURSIVE));
    }
}
