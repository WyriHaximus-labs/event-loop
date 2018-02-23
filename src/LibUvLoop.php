<?php

namespace React\EventLoop;

use React\EventLoop\Tick\FutureTickQueue;
use React\EventLoop\Timer\Timer;
use SplObjectStorage;

/**
 * @see https://github.com/bwoebi/php-uv
 */
final class LibUvLoop implements LoopInterface
{
    private $uv;
    private $futureTickQueue;
    private $timerEvents;
    private $events = array();
    private $flags = array();
    private $listeners = array();
    private $running;
    private $signals;
    private $signalEvents = array();
    private $streamListener;

    public function __construct()
    {
        $this->uv = \uv_loop_new();
        $this->futureTickQueue = new FutureTickQueue();
        $this->timerEvents = new SplObjectStorage();
        $this->streamListener = $this->createStreamListener();
        $this->signals = new SignalsHandler();
    }

    /**
     * {@inheritdoc}
     */
    public function addReadStream($stream, $listener)
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
    public function addWriteStream($stream, $listener)
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
        $this->removeStream($stream);
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
        $this->removeStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer($interval, $callback)
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
            (int)($interval * 1000),
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
        if (!isset($this->events[(int) $stream])) {
            $this->events[(int) $stream] = \uv_poll_init_socket($this->uv, $stream);
        }

        // Run in tick or else things epically fail with loop->watchers[w->fd] == w
        $this->futureTick(function () use ($stream) {
            $this->pollStream($stream);
        });
    }

    private function removeStream($stream)
    {
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

        // Run in tick or else things epically fail with loop->watchers[w->fd] == w
        $this->futureTick(function () use ($stream) {
            $this->pollStream($stream);
        });
    }

    private function pollStream($stream)
    {
        if (!isset($this->events[(int) $stream])) {
            return;
        }

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

            if (isset($this->listeners[(int) $stream]['read']) && ($events & \UV::READABLE)) {
                call_user_func($this->listeners[(int) $stream]['read'], $stream);
            }

            if (isset($this->listeners[(int) $stream]['write']) && ($events & \UV::WRITABLE)) {
                call_user_func($this->listeners[(int) $stream]['write'], $stream);
            }
        };

        return $callback;
    }
}
