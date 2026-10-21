<?php

namespace Illuminate\Tests\Queue;

use ErrorException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Console\RetryCommand;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Drivers such as webpatser/torque mark push(), pushRaw() and later() with
 * #[\NoDiscard]. Framework call sites that ignore the returned job id must
 * cast the call to (void), or PHP 8.5 raises a warning that the framework's
 * error handler turns into an ErrorException.
 */
class QueueNoDiscardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        set_error_handler(function (int $level, string $message, string $file, int $line) {
            throw new ErrorException($message, 0, $level, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();

        m::close();

        parent::tearDown();
    }

    public function testRetryCommandIgnoresNoDiscardReturnValueOfPushRaw()
    {
        $queue = new QueueNoDiscardTestQueue;

        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('ids')->once()->withNoArgs()->andReturn(['1']);
        $failer->shouldReceive('find')->once()->with('1')->andReturn((object) [
            'id' => '1',
            'connection' => 'torque',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => '1',
                'displayName' => QueueNoDiscardTestJob::class,
                'attempts' => 3,
                'data' => [
                    'commandName' => QueueNoDiscardTestJob::class,
                    'command' => serialize(new QueueNoDiscardTestJob),
                ],
            ]),
        ]);
        $failer->shouldReceive('forget')->once()->with('1');

        $container = new Application;
        $container->instance('queue.failer', $failer);

        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('torque')->andReturn($queue);
        $container->instance('queue', $manager);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch');
        $container->instance('events', $events);

        $command = new RetryCommand;
        $command->setLaravel($container);

        $exitCode = $command->run(new ArrayInput(['id' => ['all']]), new BufferedOutput);

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $queue->rawPushes);
        $this->assertSame('default', $queue->rawPushes[0]['queue']);
        $this->assertSame(0, json_decode($queue->rawPushes[0]['payload'], true)['attempts']);
    }

    public function testBulkIgnoresNoDiscardReturnValueOfPush()
    {
        $queue = new QueueNoDiscardTestQueue;
        $queue->setContainer(new Container);

        $queue->bulk(['FirstJob', 'SecondJob'], ['foo' => 'bar'], 'default');

        $this->assertSame(['FirstJob', 'SecondJob'], array_column($queue->pushes, 'job'));
    }
}

class QueueNoDiscardTestQueue extends Queue implements QueueContract
{
    public array $pushes = [];

    public array $rawPushes = [];

    public function size($queue = null)
    {
        return 0;
    }

    public function pendingSize($queue = null)
    {
        return 0;
    }

    public function delayedSize($queue = null)
    {
        return 0;
    }

    public function reservedSize($queue = null)
    {
        return 0;
    }

    public function creationTimeOfOldestPendingJob($queue = null)
    {
        return null;
    }

    #[\NoDiscard]
    public function push($job, $data = '', $queue = null): string
    {
        $this->pushes[] = compact('job', 'data', 'queue');

        return 'id-'.count($this->pushes);
    }

    #[\NoDiscard]
    public function pushRaw($payload, $queue = null, array $options = []): string
    {
        $this->rawPushes[] = compact('payload', 'queue', 'options');

        return 'raw-id-'.count($this->rawPushes);
    }

    #[\NoDiscard]
    public function later($delay, $job, $data = '', $queue = null): string
    {
        $this->pushes[] = compact('delay', 'job', 'data', 'queue');

        return 'later-id-'.count($this->pushes);
    }

    public function pop($queue = null)
    {
        return null;
    }
}

class QueueNoDiscardTestJob
{
    //
}
