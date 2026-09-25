<?php

namespace React\EventLoop\Watcher;

final class StreamWatcher
{
    public function __construct(
        public readonly int $key,
        /** @var ?callable */
        public  $readListener = null,
        /** @var ?callable */
        public $writeListener = null,
    ) {
    }
}
