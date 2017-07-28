<?php

namespace React\EventLoop;

use React\EventLoop\Tick\FutureTickQueue;
use React\EventLoop\Timer\Timer;
use React\EventLoop\Timer\TimerInterface;
use SplObjectStorage;

/**
 * @see https://github.com/bwoebi/php-uv
 */
class LibUvLoop implements LoopInterface
{
    private $uv;
    private $futureTickQueue;
    private $timerEvents;
    private $events = [];
    private $flags = [];
    private $listeners = [];
    private $running;
    private $streamListener;

    public function __construct()
    {
        $this->uv = \uv_loop_new();
        $this->futureTickQueue = new FutureTickQueue();
        $this->timerEvents = new SplObjectStorage();
        $this->streamListener = $this->createStreamListener();
    }

    /**
     * {@inheritdoc}
     */
    public function addReadStream($stream, callable $listener)
    {
        if (isset($this->listeners[(int) $stream]['read'])) {
            return;
        }

        $this->listeners[(int) $stream]['read'] = $listener;
        $this->addStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function addWriteStream($stream, callable $listener)
    {
        if (isset($this->listeners[(int) $stream]['write'])) {
            return;
        }

        $this->listeners[(int) $stream]['write'] = $listener;
        $this->addStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function removeReadStream($stream)
    {
        if (!isset($this->events[(int) $stream])) {
            return;
        }

        unset($this->listeners[(int) $stream]['read']);

        $this->____removeStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function removeWriteStream($stream)
    {
        if (!isset($this->events[(int) $stream])) {
            return;
        }

        unset($this->listeners[(int) $stream]['write']);

        $this->____removeStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function removeStream($stream)
    {
        if (isset($this->events[(int) $stream])) {
            unset($this->listeners[(int) $stream]['read']);
            unset($this->listeners[(int) $stream]['write']);

            $this->____removeStream($stream);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer($interval, callable $callback)
    {
        $timer = new Timer( $interval, $callback, false);

        $callback = function () use ($timer) {
            call_user_func($timer->getCallback(), $timer);

            if ($this->isTimerActive($timer)) {
                $this->cancelTimer($timer);
            }
        };

        $event = \uv_timer_init($this->uv);
        $this->timerEvents->attach($timer, $event);
        \uv_timer_start(
            $event,
            $interval * 1000,
            0,
            $callback
        );

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function addPeriodicTimer($interval, callable $callback)
    {
        $timer = new Timer($interval, $callback, true);

        $callback = function () use ($timer) {
            call_user_func($timer->getCallback(), $timer);
        };

        $event = \uv_timer_init($this->uv);
        $this->timerEvents->attach($timer, $event);
        \uv_timer_start(
            $event,
            $interval * 1000,
            $interval * 1000,
            $callback
        );

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(TimerInterface $timer)
    {
        if (isset($this->timerEvents[$timer])) {
            @\uv_timer_stop($this->timerEvents[$timer]);
            $this->timerEvents->detach($timer);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isTimerActive(TimerInterface $timer)
    {
        return $this->timerEvents->contains($timer);
    }

    /**
     * {@inheritdoc}
     */
    public function futureTick(callable $listener)
    {
        $this->futureTickQueue->add($listener);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->running = true;

        while ($this->running) {
            $this->futureTickQueue->tick();

            if ($this->futureTickQueue->isEmpty() && empty($this->events) && $this->timerEvents->count() === 0) {
                break;
            }

            \uv_run($this->uv, \UV::RUN_NOWAIT);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->running = false;
    }

    private function addStream($stream)
    {
        // Run in tick or else things epically fail with loop->watchers[w->fd] == w
        $this->futureTick(function () use ($stream) {
            if (!isset($this->events[(int) $stream])) {
                $this->events[(int) $stream] = \uv_poll_init_socket($this->uv, $stream);
            }

            $this->pollStream($stream);
        });
    }

    // To do: get latest changes in from react:master so we can use this method name internally
    private function ____removeStream($stream)
    {
        // Run in tick or else things epically fail with loop->watchers[w->fd] == w
        $this->futureTick(function () use ($stream) {
            if (!isset($this->events[(int) $stream])) {
                return;
            }

            if (!isset($this->listeners[(int) $stream]['read'])
            && !isset($this->listeners[(int) $stream]['write'])) {
                \uv_poll_stop($this->events[(int) $stream]);
                unset($this->events[(int) $stream]);
                unset($this->flags[(int) $stream]);
                return;
            }

            $this->pollStream($stream);
        });
    }

    private function pollStream($stream)
    {
        $flags = 0;
        if (isset($this->listeners[(int) $stream]['read'])) {
            $flags |= \UV::READABLE;
        }

        if (isset($this->listeners[(int) $stream]['write'])) {
            $flags |= \UV::WRITABLE;
        }

        if (isset($this->flags[(int) $stream]) && $this->flags[(int) $stream] == $flags) {
            return;
        }

        $this->flags[(int) $stream] = $flags;

        \uv_poll_start($this->events[(int) $stream], $flags, $this->streamListener);
    }

    /**
     * Create a stream listener
     *
     * @return callable Returns a callback
     */
    private function createStreamListener()
    {
        $callback = function ($event, $status, $events, $stream) {
            if ($status !== 0) {
                unset($this->flags[(int) $stream]);
                $this->pollStream($stream);
            }

            if (isset($this->listeners[(int) $stream]['read']) && $events & \UV::READABLE) {
                call_user_func($this->listeners[(int) $stream]['read'], $stream);
            }

            if (isset($this->listeners[(int) $stream]['write']) && $events & \UV::WRITABLE) {
                call_user_func($this->listeners[(int) $stream]['write'], $stream);
            }
        };

        return $callback;
    }
}
