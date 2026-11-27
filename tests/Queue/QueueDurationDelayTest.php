<?php

namespace Illuminate\Tests\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Time\Duration;

class QueueDurationDelayTest extends TestCase
{
    protected DatabaseQueue $queue;

    protected function setUp(): void
    {
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->bootEloquent();
        $db->setAsGlobal();

        $connection = $db->getConnection();

        $connection->getSchemaBuilder()->create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->tinyInteger('attempts')->unsigned();
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $container = new Container;
        $container->instance('events', new Dispatcher($container));

        $this->queue = new DatabaseQueue($connection, 'jobs');
        $this->queue->setContainer($container);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testLaterAcceptsADuration()
    {
        Carbon::setTestNow($time = Carbon::now());

        $this->queue->later(Duration::fromSeconds(10), 'foo', ['data']);
        $this->queue->later(Duration::fromMilliseconds(1500), 'bar');

        $rows = DB::table('jobs')->orderBy('id')->get();

        $this->assertSame(10, json_decode($rows[0]->payload, true)['delay']);
        $this->assertEquals($time->getTimestamp() + 10, $rows[0]->available_at);

        $this->assertSame(2, json_decode($rows[1]->payload, true)['delay']);
        $this->assertEquals($time->getTimestamp() + 2, $rows[1]->available_at);
    }

    public function testQueueableDelayHoldsADurationUntilItIsPushed()
    {
        Carbon::setTestNow($time = Carbon::now());

        $job = (new DurationDelayJob)->delay(Duration::fromSeconds(30));

        $this->assertInstanceOf(Duration::class, $job->delay);

        $this->queue->later($job->delay, $job);

        $row = DB::table('jobs')->sole();

        $this->assertSame(30, json_decode($row->payload, true)['delay']);
        $this->assertEquals($time->getTimestamp() + 30, $row->available_at);
    }
}

class DurationDelayJob
{
    use Queueable;

    public function handle(): void
    {
    }
}
