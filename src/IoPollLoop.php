<?php

namespace React\EventLoop;

use React\EventLoop\Tick\FutureTickQueue;
use React\EventLoop\Timer\Timer;
use React\EventLoop\Timer\Timers;
use React\EventLoop\Watcher\StreamWatcher;

final class IoPollLoop implements LoopInterface
{
    private bool $running = false;
    private \Io\Poll\Context $context;
    private FutureTickQueue $futureTickQueue;
    private Timers $timers;
    private bool $pcntl;
    private SignalsHandler $signals;
    /** @var array<\Io\Poll\Watcher> */
    private array $watchers = [];

    public function __construct()
    {
        $this->context = new \Io\Poll\Context();
        $this->futureTickQueue = new FutureTickQueue();
        $this->timers = new Timers();
        $this->pcntl = \function_exists('pcntl_signal') && \function_exists('pcntl_signal_dispatch');;
        $this->signals = new SignalsHandler();
    }

    public function addReadStream($stream, $listener)
    {
        $this->manageStream($stream, \Io\Poll\Event::Read, $listener);
    }

    public function addWriteStream($stream, $listener)
    {
        $this->manageStream($stream, \Io\Poll\Event::Write, $listener);
    }

    public function removeReadStream($stream)
    {
        $this->manageStream($stream, \Io\Poll\Event::Read);
    }

    public function removeWriteStream($stream)
    {
        $this->manageStream($stream, \Io\Poll\Event::Write);
    }

    public function addTimer($interval, $callback)
    {
        $timer = new Timer($interval, $callback, false);

        $this->timers->add($timer);

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback)
    {
        $timer = new Timer($interval, $callback, true);

        $this->timers->add($timer);

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer)
    {
        $this->timers->cancel($timer);
    }

    public function futureTick($listener)
    {
        $this->futureTickQueue->add($listener);
    }

    public function addSignal($signal, $listener)
    {
        if ($this->pcntl === false) {
            throw new \BadMethodCallException('Event loop feature "signals" isn\'t supported by the "StreamSelectLoop"');
        }

        $first = $this->signals->count($signal) === 0;
        $this->signals->add($signal, $listener);

        if ($first) {
            \pcntl_signal($signal, [$this->signals, 'call']);
        }
    }

    public function removeSignal($signal, $listener)
    {
        if (!$this->signals->count($signal)) {
            return;
        }

        $this->signals->remove($signal, $listener);

        if ($this->signals->count($signal) === 0) {
            \pcntl_signal($signal, \SIG_DFL);
        }
    }

    public function run()
    {
        $this->running = true;

        while ($this->running) {
            $this->futureTickQueue->tick();

            $this->timers->tick();

            // Future-tick queue has pending callbacks ...
            if (!$this->futureTickQueue->isEmpty()) {
                $duration = \Time\Duration::fromSeconds(0);

                // There is a pending timer, only block until it is due ...
            } elseif ($scheduledAt = $this->timers->getFirst()) {
                $timeout = $scheduledAt - $this->timers->getTime();
                if ($timeout < 0) {
                    $timeout = 0;
                }

                $seconds = (int)$timeout;
                $nanoseconds = (int)(($timeout - $seconds) * 1_000_000_000);

                $duration = \Time\Duration::fromSeconds($seconds, $nanoseconds);

                // The only possible event is stream or signal activity, so wait forever ...
            } elseif ($this->watchers || !$this->signals->isEmpty()) {
                $duration = null;

                // There's nothing left to do ...
            } else {
                break;
            }

            try {
                foreach ($this->context->wait($duration) as $watcher) {
                    $stream = $watcher->getHandle()->getStream();
                    $streamWatcher = $watcher->getData();
                    $triggeredEvents = $watcher->getTriggeredEvents();

                    if ($streamWatcher->readListener !== null && in_array(\Io\Poll\Event::Read, $triggeredEvents)) {
                        \call_user_func($streamWatcher->readListener, $stream);
                    }

                    if ($streamWatcher->writeListener !== null && in_array(\Io\Poll\Event::Write, $triggeredEvents)) {
                        \call_user_func($streamWatcher->writeListener, $stream);
                    }
                }
            } catch (\Io\Poll\FailedPollWaitException $failedPollWaitException) {
                if ($failedPollWaitException->getCode() === \Io\Poll\FailedPollWaitException::ERROR_INTERRUPTED) {
                    \pcntl_signal_dispatch();
                } else {
                    throw $failedPollWaitException;
                }
            }
        }
    }

    public function stop()
    {
        $this->running = false;
    }

    private function manageStream($stream, \Io\Poll\Event $event, ?callable $listener = null)
    {
        $key = (int) $stream;
        if (!array_key_exists($key, $this->watchers)) {
            if ($listener === null) {
                return;
            }

            $streamWatcher = new StreamWatcher($key);
            $this->updateStreamWatcherListener($streamWatcher, $event, $listener);
            $handle = new \StreamPollHandle($stream);
            $watcher = $this->context->add($handle, [$event], $streamWatcher);
            $this->watchers[$key] = $watcher;

            return;
        }

        if (!isset($watcher)) {
            $watcher = $this->watchers[$key];
        }

        if (!$watcher->isActive()) {
            $watcher->remove();
            unset($this->watchers[$key]);

            return;
        }

        $events = $watcher->getWatchedEvents();
        $events = array_filter($events, static fn (\Io\Poll\Event $watchedEvent)=> $watchedEvent === $event);
        if ($listener !== null) {
            $events[] = $event;
        }
        $this->updateStreamWatcherListener($watcher->getData(), $event, $listener);

        if (count($events) > 0 && ($watcher->getData()->readListener !== null || $watcher->getData()->writeListener !== null)) {
            $watcher->modifyEvents($events);

            return;
        }

        $watcher->remove();
        unset($this->watchers[$key]);
    }

    private function updateStreamWatcherListener(StreamWatcher $streamWatcher, \Io\Poll\Event $event, ?callable $listener = null): void
    {
        if ($event === \Io\Poll\Event::Read) {
            $streamWatcher->readListener = $listener;
        } elseif ($event === \Io\Poll\Event::Write) {
            $streamWatcher->writeListener = $listener;
        }
    }
}
