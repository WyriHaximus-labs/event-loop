<?php

namespace React\Tests\EventLoop;

use React\EventLoop\LibUvLoop;

class LibUvLoopTest extends AbstractLoopTest
{
    public function createLoop()
    {
        if (!function_exists('uv_default_loop')) {
            $this->markTestSkipped('libuv tests skipped because ext-libuv is not installed.');
        }

        return new LibUvLoop();
    }
}
