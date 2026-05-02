<?php

namespace Illuminate\Foundation\Console\Bench\Scenarios;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Console\Bench\Scenario;
use Throwable;

class RedisScenario implements Scenario
{
    private const KEY_PREFIX = 'fledge:bench:';

    private ?Repository $store = null;

    public function __construct(private readonly Factory $cacheFactory)
    {
        //
    }

    public function name(): string
    {
        return 'redis';
    }

    public function label(): string
    {
        $client = env('REDIS_CLIENT', config('database.redis.client', 'fledge-fiber'));

        return sprintf('Redis cache throughput (%s client)', $client);
    }

    public function preflight(): ?string
    {
        try {
            $this->store = $this->cacheFactory->store('redis');
            $this->store->put(self::KEY_PREFIX.'preflight', 'ok', 5);
            $value = $this->store->get(self::KEY_PREFIX.'preflight');
            $this->store->forget(self::KEY_PREFIX.'preflight');
        } catch (Throwable $e) {
            return $this->renderUnreachable($e->getMessage());
        }

        if ($value !== 'ok') {
            return 'Redis cache write/read returned an unexpected value ('.var_export($value, true).'). Check your REDIS_* configuration and the cache.stores.redis serializer.';
        }

        return null;
    }

    public function setup(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->store->put(self::KEY_PREFIX.$i, "value-$i", 60);
        }
    }

    public function variants(): array
    {
        $store = $this->store;
        $i = 0;

        return [
            'cache_get' => function () use ($store, &$i) {
                $store->get(self::KEY_PREFIX.($i++ % 100));
            },
            'cache_put' => function () use ($store, &$i) {
                $store->put(self::KEY_PREFIX.'w'.($i++ % 100), 'x', 60);
            },
        ];
    }

    public function teardown(): void
    {
        if ($this->store === null) {
            return;
        }

        for ($i = 0; $i < 100; $i++) {
            $this->store->forget(self::KEY_PREFIX.$i);
            $this->store->forget(self::KEY_PREFIX.'w'.$i);
        }
        $this->store->forget(self::KEY_PREFIX.'preflight');
    }

    private function renderUnreachable(string $error): string
    {
        $host = env('REDIS_HOST', '127.0.0.1');
        $port = env('REDIS_PORT', '6379');
        $client = env('REDIS_CLIENT', config('database.redis.client', 'fledge-fiber'));

        return <<<TEXT
        Redis is not reachable at {$host}:{$port} (client: {$client}).
        Underlying error: {$error}

        The redis scenario forces the `redis` cache store, it does not fall back to the default driver.
        To run it, start a Redis instance first:

            docker run --rm -d -p 6379:6379 --name fledge-bench-redis redis:7

        Run the bench, then stop the container when done:

            docker stop fledge-bench-redis

        Alternatively, point REDIS_HOST / REDIS_PORT at a Redis you already run locally.
        TEXT;
    }
}
