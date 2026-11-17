<?php

namespace Illuminate\Tests\Queue;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Events\Dispatcher as EventsDispatcher;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\JobInterrupted;
use Illuminate\Queue\Events\WorkerInterrupted;
use Illuminate\Queue\Events\WorkerPausing;
use Illuminate\Queue\Events\WorkerResuming;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;

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

    public function testTimeoutHandlerIsResetBeforeThePostJobTick()
    {
        $worker = null;

        $job = new SignalFakeJob(function () use (&$worker) {
            $worker->calls[] = 'fire';
        });

        $worker = new OrderRecordingWorker(
            new SignalFakeManager(new SignalFakeConnection(['queue' => [$job]])),
            $this->events,
            Container::getInstance()->make(ExceptionHandler::class),
            fn () => false,
        );

        try {
            $status = $worker->daemon('default', 'queue', $this->workerOptions());
        } finally {
            $worker->cancelSignalWatchers();
        }

        $this->assertSame(0, $status);
        $this->assertTrue($job->fired);

        $afterJob = array_slice($worker->calls, array_search('fire', $worker->calls, true) + 1, 2);

        $this->assertSame(['reset', 'tick'], $afterJob, 'The event loop ticked while the job timeout was still armed.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCpuBoundInterruptibleJobIsNotifiedMidJobOnSigterm()
    {
        $this->forceStreamSelectDriver();

        [$worker, $first, $second, $interruptible, $seen] = $this->workerWithInterruptibleJob();

        try {
            $status = $worker->daemon('default', 'queue', $this->workerOptions());
        } finally {
            $worker->cancelSignalWatchers();
        }

        $this->assertInterruptedMidJob($status, $first, $second, $interruptible, $seen);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCpuBoundInterruptibleJobInsideFiberIsNotifiedMidJobOnSigterm()
    {
        $this->forceStreamSelectDriver();

        [$worker, $first, $second, $interruptible, $seen] = $this->workerWithInterruptibleJob();

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

        $this->assertInterruptedMidJob($status, $first, $second, $interruptible, $seen);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListeningTwiceDoesNotHandleMidJobSignalsTwice()
    {
        $this->forceStreamSelectDriver();

        [, $first, $second, $interruptible, $seen] = $this->workerWithInterruptibleJob();

        $worker = new DoubleListeningSignalWorker(
            new SignalFakeManager(new SignalFakeConnection(['queue' => [$first, $second]])),
            $this->events,
            Container::getInstance()->make(ExceptionHandler::class),
            fn () => false,
        );

        try {
            $status = $worker->daemon('default', 'queue', $this->workerOptions());
        } finally {
            $worker->cancelSignalWatchers();
        }

        $this->assertInterruptedMidJob($status, $first, $second, $interruptible, $seen);
        $this->assertSame([SIGTERM => 0], $worker->signalsHandledInJob());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTwoSignalsDuringOneJobAreEachHandledOnce()
    {
        $this->forceStreamSelectDriver();

        $interruptible = new SignalInterruptibleCommand;
        $seen = new \ArrayObject;

        $first = new SignalFakeJob(function () use ($interruptible, $seen) {
            posix_kill(getmypid(), SIGTERM);
            posix_kill(getmypid(), SIGTERM);

            $seen['signals'] = $interruptible->signals;
            $seen['workerInterrupted'] = count($this->events->dispatched(WorkerInterrupted::class));
        }, $interruptible);
        $second = new SignalFakeJob;

        $worker = $this->makeWorker([$first, $second]);

        try {
            $status = $worker->daemon('default', 'queue', $this->workerOptions());
        } finally {
            $worker->cancelSignalWatchers();
        }

        // Stock handles each delivery: two WorkerInterrupted, two interrupted() calls, two JobInterrupted.
        $this->assertSame(0, $status);
        $this->assertSame([SIGTERM, SIGTERM], $seen['signals']);
        $this->assertSame(2, $seen['workerInterrupted']);
        $this->assertSame([SIGTERM, SIGTERM], $interruptible->signals);
        $this->assertFalse($second->fired);
        $this->events->assertDispatchedTimes(WorkerInterrupted::class, 2);
        $this->events->assertDispatchedTimes(JobInterrupted::class, 2);
        $this->assertSame([SIGTERM => 0], $worker->signalsHandledInJob());
    }

    public function testYieldingInterruptibleJobIsNotifiedOnceThroughTheWatcher()
    {
        $interruptible = new SignalInterruptibleCommand;
        $seen = new \ArrayObject;

        $first = new SignalFakeJob(function () use ($interruptible, $seen) {
            $suspension = EventLoop::getSuspension();

            // Send the signal from the loop fiber so only the Revolt watcher can handle it.
            EventLoop::defer(static function (): void {
                posix_kill(getmypid(), SIGTERM);
            });
            EventLoop::delay(0.05, static fn () => $suspension->resume());

            $suspension->suspend();

            $seen['signals'] = $interruptible->signals;
        }, $interruptible);
        $second = new SignalFakeJob;

        $worker = $this->makeWorker([$first, $second]);

        try {
            $status = $worker->daemon('default', 'queue', $this->workerOptions());
        } finally {
            $worker->cancelSignalWatchers();
        }

        $this->assertSame(0, $status);
        $this->assertSame([SIGTERM], $seen['signals'], 'The job was not notified while it ran.');
        $this->assertSame([SIGTERM], $interruptible->signals);
        $this->assertFalse($second->fired);
        $this->events->assertDispatchedOnce(JobInterrupted::class);
        $this->events->assertDispatchedOnce(WorkerInterrupted::class);
    }

    public function testSignalWhileIdleInterruptsTheWorker()
    {
        $connection = new SignalFakeConnection(['queue' => []]);
        $connection->onEmpty = static fn () => posix_kill(getmypid(), SIGTERM);

        $worker = new SignalAwareWorker(
            new SignalFakeManager($connection),
            $this->events,
            Container::getInstance()->make(ExceptionHandler::class),
            fn () => false,
        );

        $options = $this->workerOptions();
        $options->stopWhenEmpty = false;
        // A regression must fail the test, not hang the suite.
        $options->maxTime = 5;

        try {
            $status = $worker->daemon('default', 'queue', $options);
        } finally {
            $worker->cancelSignalWatchers();
        }

        $this->assertSame(0, $status);
        $this->events->assertDispatchedOnce(WorkerInterrupted::class);
        $this->events->assertNotDispatched(JobInterrupted::class);
    }

    public function testWithoutJobSignalChainingSignalsAreHandledBetweenJobs()
    {
        $interruptible = new SignalInterruptibleCommand;
        $seen = new \ArrayObject;

        $first = new SignalFakeJob(function () use ($interruptible, $seen) {
            posix_kill(getmypid(), SIGTERM);

            $seen['signals'] = $interruptible->signals;
            $seen['workerInterrupted'] = count($this->events->dispatched(WorkerInterrupted::class));
        }, $interruptible);
        $second = new SignalFakeJob;

        $worker = new NonChainingSignalWorker(
            new SignalFakeManager(new SignalFakeConnection(['queue' => [$first, $second]])),
            $this->events,
            Container::getInstance()->make(ExceptionHandler::class),
            fn () => false,
        );

        try {
            $status = $worker->daemon('default', 'queue', $this->workerOptions());
        } finally {
            $worker->cancelSignalWatchers();
        }

        $this->assertSame(0, $status);
        $this->assertTrue($first->fired);
        $this->assertFalse($second->fired, 'The worker kept taking jobs after SIGTERM.');
        $this->assertSame([], $seen['signals']);
        $this->assertSame(0, $seen['workerInterrupted']);
        $this->assertSame([], $interruptible->signals);
        $this->events->assertDispatchedOnce(WorkerInterrupted::class);
        $this->events->assertNotDispatched(JobInterrupted::class);
    }

    public function testPauseAndResumeSignalsStillWorkMidJob()
    {
        $first = new SignalFakeJob(function () {
            posix_kill(getmypid(), SIGUSR2);

            EventLoop::delay(0.05, static function (): void {
                posix_kill(getmypid(), SIGCONT);
            });
        });
        $second = new SignalFakeJob;

        $worker = $this->makeWorker([$first, $second]);

        // pauseWorker() stops a stopWhenEmpty worker while paused (stock), so bound the run by job count.
        $options = $this->workerOptions();
        $options->stopWhenEmpty = false;
        $options->maxJobs = 2;
        // A regression must fail the test, not hang the suite; a short pause sleep keeps it fast.
        $options->maxTime = 5;
        $options->sleep = 0.1;

        try {
            $status = $worker->daemon('default', 'queue', $options);
        } finally {
            $worker->cancelSignalWatchers();
        }

        $this->assertSame(0, $status);
        $this->assertTrue($first->fired);
        $this->assertTrue($second->fired, 'The worker did not resume after SIGCONT.');
        $this->events->assertDispatchedOnce(WorkerPausing::class);
        $this->events->assertDispatchedOnce(WorkerResuming::class);
        $this->events->assertNotDispatched(WorkerInterrupted::class);
    }

    private function workerWithInterruptibleJob(): array
    {
        $interruptible = new SignalInterruptibleCommand;
        $seen = new \ArrayObject;

        $first = new SignalFakeJob(function () use ($interruptible, $seen) {
            posix_kill(getmypid(), SIGTERM);

            // CPU-bound: the job never yields to the event loop before it returns.
            $seen['signals'] = $interruptible->signals;
            $seen['jobInterrupted'] = count($this->events->dispatched(JobInterrupted::class));
            $seen['workerInterrupted'] = count($this->events->dispatched(WorkerInterrupted::class));
        }, $interruptible);
        $second = new SignalFakeJob;

        return [$this->makeWorker([$first, $second]), $first, $second, $interruptible, $seen];
    }

    private function assertInterruptedMidJob($status, $first, $second, $interruptible, $seen): void
    {
        $this->assertSame(0, $status);
        $this->assertTrue($first->fired);
        $this->assertSame([SIGTERM], $seen['signals'], 'The job was not notified before it returned.');
        $this->assertSame(1, $seen['jobInterrupted']);
        $this->assertSame(1, $seen['workerInterrupted']);
        $this->assertSame([SIGTERM], $interruptible->signals);
        $this->assertFalse($second->fired, 'The worker kept taking jobs after SIGTERM.');
        $this->events->assertDispatchedOnce(JobInterrupted::class);
        $this->events->assertDispatched(JobInterrupted::class, fn ($event) => $event->connectionName === 'default'
            && $event->job === $first
            && $event->signal === SIGTERM);
        $this->events->assertDispatchedOnce(WorkerInterrupted::class);
        $this->events->assertDispatched(WorkerInterrupted::class, fn ($event) => $event->signal === SIGTERM);
    }

    /**
     * Mid-job chaining needs the StreamSelectDriver, so force it even when ext-uv, ext-ev or ext-event is loaded.
     *
     * Revolt refuses to swap a driver that has run, so these tests run in their own process: the swap happens
     * before the loop starts, and the process ends with the test, which stands in for restoring the old driver.
     */
    private function forceStreamSelectDriver(): void
    {
        if (! EventLoop::getDriver() instanceof StreamSelectDriver) {
            EventLoop::setDriver(new StreamSelectDriver);
        }
    }

    private function makeWorker(array $jobs): SignalAwareWorker
    {
        return new SignalAwareWorker(
            new SignalFakeManager(new SignalFakeConnection(['queue' => $jobs])),
            $this->events,
            Container::getInstance()->make(ExceptionHandler::class),
            fn () => false,
        );
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

    public function signalsHandledInJob(): array
    {
        return $this->signalsHandledInJob;
    }

    public function memoryExceeded($memoryLimit)
    {
        return false;
    }
}

class DoubleListeningSignalWorker extends SignalAwareWorker
{
    protected function listenForSignals($connectionName = null, $queue = null, ?WorkerOptions $options = null)
    {
        parent::listenForSignals($connectionName, $queue, $options);

        // Chain again directly, then listen again, as a second daemon() on the same worker would.
        $this->chainJobSignalHandler(SIGTERM, $connectionName, $queue, $options);

        parent::listenForSignals($connectionName, $queue, $options);
    }
}

class NonChainingSignalWorker extends SignalAwareWorker
{
    protected function supportsJobSignalChaining(): bool
    {
        return false;
    }
}

class SignalInterruptibleCommand implements Interruptible
{
    public array $signals = [];

    public function interrupted(int $signal): void
    {
        $this->signals[] = $signal;
    }
}

class SignalCallQueuedHandler extends CallQueuedHandler
{
    public function __construct(object $command)
    {
        $this->runningCommand = $command;
    }
}

class OrderRecordingWorker extends SignalAwareWorker
{
    public array $calls = [];

    protected function tickEventLoop(): void
    {
        $this->calls[] = 'tick';

        parent::tickEventLoop();
    }

    protected function resetTimeoutHandler()
    {
        $this->calls[] = 'reset';

        parent::resetTimeoutHandler();
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
    public ?Closure $onEmpty = null;

    public function __construct(public array $jobs)
    {
    }

    public function pop($queue)
    {
        $job = array_shift($this->jobs[$queue]);

        if ($job === null && $this->onEmpty) {
            ($this->onEmpty)();

            $this->onEmpty = null;
        }

        return $job;
    }

    public function getConnectionName()
    {
        return 'default';
    }
}

class SignalFakeJob extends Job implements QueueJobContract
{
    public bool $fired = false;

    public function __construct(public ?Closure $callback = null, ?Interruptible $command = null)
    {
        $this->connectionName = 'default';
        $this->queue = 'queue';

        if ($command) {
            $this->instance = new SignalCallQueuedHandler($command);
        }
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
