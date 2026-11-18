<?php

namespace Illuminate\Tests\Integration\Concurrency;

use Illuminate\Concurrency\FiberDriver;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;

use function Fledge\Async\delay;

/**
 * Redis connections are shared between Concurrency::run() tasks; only database connections are per task.
 */
class FiberDriverSharedRedisConnectionTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('database.redis', [
            'client' => 'phpredis',
            'options' => ['prefix' => 'fledge_shared_redis_test_'],
            'default' => [
                'host' => Env::get('REDIS_HOST', '127.0.0.1'),
                'port' => (int) Env::get('REDIS_PORT', 6379),
                'database' => 5,
                'timeout' => 0.5,
            ],
        ]);

        $app->bind('redis.connector.phpredis', fn () => new OpenedRedisConnectionCounter(new PhpRedisConnector));
    }

    protected function setUp(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The redis extension is not installed.');
        }

        $host = Env::get('REDIS_HOST', '127.0.0.1');
        $port = (int) Env::get('REDIS_PORT', 6379);

        $socket = @fsockopen($host, $port, $errno, $error, 0.5);

        if ($socket === false || fwrite($socket, "PING\r\n") === false || ! str_starts_with((string) fgets($socket), '+PONG')) {
            $this->markTestSkipped("No Redis answers on {$host}:{$port}; set REDIS_HOST and REDIS_PORT.");
        }

        fclose($socket);

        parent::setUp();

        OpenedRedisConnectionCounter::$opened = 0;
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            Redis::connection()->disconnect();
        }

        parent::tearDown();
    }

    public function test_tasks_use_the_shared_redis_connection_and_open_none_of_their_own()
    {
        $parent = Redis::connection();
        $parent->ping();

        $this->assertSame(1, OpenedRedisConnectionCounter::$opened);

        $connections = (new FiberDriver)->run([
            function () {
                delay(0.01);

                $connection = Redis::connection();
                $connection->ping();

                return $connection;
            },
            fn () => Redis::connection('default'),
        ]);

        $this->assertSame($parent, $connections[0]);
        $this->assertSame($parent, $connections[1]);
        $this->assertSame(['default' => $parent], Redis::connections());
        $this->assertSame(1, OpenedRedisConnectionCounter::$opened);
    }
}

class OpenedRedisConnectionCounter implements Connector
{
    public static int $opened = 0;

    public function __construct(protected Connector $connector)
    {
        //
    }

    public function connect(array $config, array $options)
    {
        static::$opened++;

        return $this->connector->connect($config, $options);
    }

    public function connectToCluster(array $config, array $clusterOptions, array $options)
    {
        static::$opened++;

        return $this->connector->connectToCluster($config, $clusterOptions, $options);
    }
}
