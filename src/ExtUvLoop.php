<?php

namespace React\EventLoop;

use React\EventLoop\Tick\FutureTickQueue;
use React\EventLoop\Timer\Timer;
use SplObjectStorage;

/**
 * @see https://github.com/bwoebi/php-uv
 */
final class ExtUvLoop implements LoopInterface
{
    private $uv;
    private $futureTickQueue;
    private $timers;
    private $streamEvents = array();
    private $flags = array();
    private $readStreams = array();
    private $writeStreams = array();
    private $running;
    private $signals;
    private $signalEvents = array();
    private $streamListener;

    public function __construct()
    {
        if (!function_exists('uv_loop_new')) {
            throw new BadMethodCallException('Cannot create LibUvLoop, ext-uv extension missing');
        }

        $this->uv = \uv_loop_new();
        $this->futureTickQueue = new FutureTickQueue();
        $this->timers = new SplObjectStorage();
        $this->streamListener = $this->createStreamListener();
        $this->signals = new SignalsHandler();
    }

    /**
     * {@inheritdoc}
     */
    public function addReadStream($stream, $listener)
    {
        if (isset($this->readStreams[(int) $stream])) {
            return;
        }

        $this->readStreams[(int) $stream] = $listener;
        $this->addStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function addWriteStream($stream, $listener)
    {
        if (isset($this->writeStreams[(int) $stream])) {
            return;
        }

        $this->writeStreams[(int) $stream] = $listener;
        $this->addStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function removeReadStream($stream)
    {
        if (!isset($this->streamEvents[(int) $stream])) {
            return;
        }

        unset($this->readStreams[(int) $stream]);
        $this->removeStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function removeWriteStream($stream)
    {
        if (!isset($this->streamEvents[(int) $stream])) {
            return;
        }

        unset($this->writeStreams[(int) $stream]);
        $this->removeStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer($interval, $callback)
    {
        $timer = new Timer( $interval, $callback, false);

        $that = $this;
        $timers = $this->timers;
        $callback = function () use ($timer, $timers, $that) {
            call_user_func($timer->getCallback(), $timer);

            if ($timers->contains($timer)) {
                $that->cancelTimer($timer);
            }
        };

        $event = \uv_timer_init($this->uv);
        $this->timers->attach($timer, $event);
        \uv_timer_start(
            $event,
            (int)ceil($interval * 1000),
            0,
            $callback
        );

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function addPeriodicTimer($interval, $callback)
    {
        $timer = new Timer($interval, $callback, true);

        $callback = function () use ($timer) {
            call_user_func($timer->getCallback(), $timer);
        };

        $event = \uv_timer_init($this->uv);
        $this->timers->attach($timer, $event);
        \uv_timer_start(
            $event,
            (int)ceil($interval * 1000),
            (int)ceil($interval * 1000),
            $callback
        );

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(TimerInterface $timer)
    {
        if (isset($this->timers[$timer])) {
            @\uv_timer_stop($this->timers[$timer]);
            $this->timers->detach($timer);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function futureTick($listener)
    {
        $this->futureTickQueue->add($listener);
    }

    public function addSignal($signal, $listener)
    {
        $this->signals->add($signal, $listener);

        if (!isset($this->signalEvents[$signal])) {
            $signals = $this->signals;
            $this->signalEvents[$signal] = \uv_signal_init($this->uv);
            \uv_signal_start($this->signalEvents[$signal], function () use ($signals, $signal) {
                $signals->call($signal);
            }, $signal);
        }
    }

    public function removeSignal($signal, $listener)
    {
        $this->signals->remove($signal, $listener);

        if (isset($this->signalEvents[$signal]) && $this->signals->count($signal) === 0) {
            \uv_signal_stop($this->signalEvents[$signal]);
            unset($this->signalEvents[$signal]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->running = true;

        while ($this->running) {
            $this->futureTickQueue->tick();

            $hasPendingCallbacks = !$this->futureTickQueue->isEmpty();
            $wasJustStopped = !$this->running;
            $nothingLeftToDo = !$this->readStreams
                && !$this->writeStreams
                && !$this->timers->count()
                && $this->signals->isEmpty();

            // Use UV::RUN_ONCE when there are only I/O events active in the loop and block until one of those triggers,
            // otherwise use UV::RUN_NOWAIT.
            // @link http://docs.libuv.org/en/v1.x/loop.html#c.uv_run
            $flags = \UV::RUN_ONCE;
            if ($wasJustStopped || $hasPendingCallbacks) {
                $flags = \UV::RUN_NOWAIT;
            } elseif ($nothingLeftToDo) {
                break;
            }

            \uv_run($this->uv, $flags);
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
        if (!isset($this->streamEvents[(int) $stream])) {
            $this->streamEvents[(int) $stream] = \uv_poll_init_socket($this->uv, $stream);
        }

        // Run in tick or else things epically fail with loop->watchers[w->fd] == w
        $this->futureTick(function () use ($stream) {
            $this->pollStream($stream);
        });
    }

    private function removeStream($stream)
    {
        if (!isset($this->streamEvents[(int) $stream])) {
            return;
        }

        if (!isset($this->readStreams[(int) $stream])
            && !isset($this->writeStreams[(int) $stream])) {
            \uv_poll_stop($this->streamEvents[(int) $stream]);
            unset($this->streamEvents[(int) $stream]);
            unset($this->flags[(int) $stream]);
            return;
        }

        // Run in tick or else things epically fail with loop->watchers[w->fd] == w
        $this->futureTick(function () use ($stream) {
            $this->pollStream($stream);
        });
    }

    private function pollStream($stream)
    {
        if (!isset($this->streamEvents[(int) $stream])) {
            return;
        }

        $flags = 0;
        if (isset($this->readStreams[(int) $stream])) {
            $flags |= \UV::READABLE;
        }

        if (isset($this->writeStreams[(int) $stream])) {
            $flags |= \UV::WRITABLE;
        }

        if (isset($this->flags[(int) $stream]) && $this->flags[(int) $stream] == $flags) {
            return;
        }

        $this->flags[(int) $stream] = $flags;

        \uv_poll_start($this->streamEvents[(int) $stream], $flags, $this->streamListener);
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

            if (isset($this->readStreams[(int) $stream]) && ($events & \UV::READABLE)) {
                call_user_func($this->readStreams[(int) $stream], $stream);
            }

            if (isset($this->writeStreams[(int) $stream]) && ($events & \UV::WRITABLE)) {
                call_user_func($this->writeStreams[(int) $stream], $stream);
            }
        };

        return $callback;
    }
}