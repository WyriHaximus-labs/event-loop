<?php

namespace React\Tests\EventLoop;

use React\EventLoop\ExtUvLoop;

class ExtUvLoopTest extends AbstractLoopTest
{
    public function createLoop()
    {
        if (!function_exists('uv_default_loop')) {
            $this->markTestSkipped('uv tests skipped because ext-uv is not installed.');
        }

        return new ExtUvLoop();
    }
}
