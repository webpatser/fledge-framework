<?php

namespace Illuminate\Tests\Queue;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Events\Dispatcher as EventsDispatcher;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\Events\WorkerInterrupted;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
class QueueWorkerSignalTest extends TestCase
{
    public $events;

    protected bool $previousAsyncSignals;

    protected function setUp(): void
    {
        $this->events = new EventFake(new EventsDispatcher);

        Container::setInstance($container = new Container);

        $container->instance(Dispatcher::class, $this->events);
        $container->instance(ExceptionHandler::class, new ExceptionHandlerFake(new Handler($container)));

        // Safety net: a SIGTERM that no Revolt watcher picks up must not kill the test run.
        $this->previousAsyncSignals = pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static fn () => null);
    }

    protected function tearDown(): void
    {
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_async_signals($this->previousAsyncSignals);

        Container::setInstance();
    }

    public function testBusyWorkerStopsAfterCurrentJobOnSigterm()
    {
        [$worker, $first, $second, $third] = $this->workerWithBacklog();

        try {
            $status = $worker->daemon('default', 'queue', $this->workerOptions());
        } finally {
            $worker->cancelSignalWatchers();
        }

        $this->assertSame(0, $status);
        $this->assertTrue($first->fired);
        $this->assertFalse($second->fired, 'The worker kept taking jobs after SIGTERM.');
        $this->assertFalse($third->fired);
        $this->events->assertDispatched(WorkerInterrupted::class, fn ($event) => $event->signal === SIGTERM);
    }

    public function testBusyWorkerInsideFiberStopsAfterCurrentJobOnSigterm()
    {
        [$worker, $first, $second, $third] = $this->workerWithBacklog();

        $main = EventLoop::getSuspension();

        EventLoop::queue(function () use ($worker, $main) {
            try {
                $main->resume($worker->daemon('default', 'queue', $this->workerOptions()));
            } catch (\Throwable $e) {
                $main->throw($e);
            } finally {
                $worker->cancelSignalWatchers();
            }
        });

        $status = $main->suspend();

        $this->assertSame(0, $status);
        $this->assertTrue($first->fired);
        $this->assertFalse($second->fired, 'The worker kept taking jobs after SIGTERM.');
        $this->assertFalse($third->fired);
        $this->events->assertDispatched(WorkerInterrupted::class, fn ($event) => $event->signal === SIGTERM);
    }

    private function workerWithBacklog(): array
    {
        $first = new SignalFakeJob(fn () => posix_kill(getmypid(), SIGTERM));
        $second = new SignalFakeJob;
        $third = new SignalFakeJob;

        $worker = new SignalAwareWorker(
            new SignalFakeManager(new SignalFakeConnection(['queue' => [$first, $second, $third]])),
            $this->events,
            Container::getInstance()->make(ExceptionHandler::class),
            fn () => false,
        );

        return [$worker, $first, $second, $third];
    }

    private function workerOptions(): WorkerOptions
    {
        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->timeout = 0;
        $options->stopWhenEmpty = true;

        return $options;
    }
}

class SignalAwareWorker extends Worker
{
    public function cancelSignalWatchers(): void
    {
        foreach ($this->signalWatchers as $watcher) {
            EventLoop::cancel($watcher);
        }

        $this->signalWatchers = [];
    }

    public function memoryExceeded($memoryLimit)
    {
        return false;
    }
}

class SignalFakeManager extends QueueManager
{
    public function __construct(public $fakeConnection)
    {
    }

    public function connection($name = null)
    {
        return $this->fakeConnection;
    }
}

class SignalFakeConnection
{
    public function __construct(public array $jobs)
    {
    }

    public function pop($queue)
    {
        return array_shift($this->jobs[$queue]);
    }

    public function getConnectionName()
    {
        return 'default';
    }
}

class SignalFakeJob extends Job implements QueueJobContract
{
    public bool $fired = false;

    public function __construct(public ?Closure $callback = null)
    {
        $this->connectionName = 'default';
        $this->queue = 'queue';
    }

    public function getJobId()
    {
        return spl_object_id($this);
    }

    public function getRawBody()
    {
        return '{"job":"signal-fake","displayName":"signal-fake"}';
    }

    public function attempts()
    {
        return 1;
    }

    public function fire()
    {
        $this->fired = true;

        if ($this->callback) {
            ($this->callback)();
        }
    }
}
