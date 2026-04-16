<?php

namespace Illuminate\Cache;

use Fiber;
use Illuminate\Cache\Concerns\SuspendsFibers;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

class RedisTagSet extends TagSet
{
    use SuspendsFibers;
    /**
     * Add a reference entry to the tag set's underlying sorted set.
     *
     * @param  string  $key
     * @param  int|null  $ttl
     * @param  string|null  $updateWhen
     * @return void
     */
    public function addEntry(string $key, ?int $ttl = null, $updateWhen = null)
    {
        $ttl = is_null($ttl) ? -1 : Carbon::now()->addSeconds($ttl)->getTimestamp();

        $tagIds = $this->tagIds();

        if ($this->inFiber() && count($tagIds) > 1) {
            $futures = [];

            foreach ($tagIds as $tagKey) {
                $futures[] = async(fn () => $this->zaddEntry($tagKey, $ttl, $key, $updateWhen));
            }

            await($futures);
        } else {
            foreach ($tagIds as $tagKey) {
                $this->zaddEntry($tagKey, $ttl, $key, $updateWhen);
            }
        }
    }

    /**
     * Add a single entry to the given tag's sorted set.
     */
    protected function zaddEntry(string $tagKey, int|float $ttl, string $key, $updateWhen): void
    {
        if ($updateWhen) {
            $this->store->connection()->zadd($this->store->getPrefix().$tagKey, $updateWhen, $ttl, $key);
        } else {
            $this->store->connection()->zadd($this->store->getPrefix().$tagKey, $ttl, $key);
        }
    }

    /**
     * Get all of the cache entry keys for the tag set.
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function entries()
    {
        $connection = $this->store->connection();

        $defaultCursorValue = match (true) {
            $connection instanceof PhpRedisConnection && version_compare(phpversion('redis'), '6.1.0', '>=') => null,
            default => '0',
        };

        return new LazyCollection(function () use ($connection, $defaultCursorValue) {
            foreach ($this->tagIds() as $tagKey) {
                $cursor = $defaultCursorValue;

                do {
                    $results = $connection->zscan(
                        $this->store->getPrefix().$tagKey,
                        $cursor,
                        ['match' => '*', 'count' => 1000]
                    );

                    if (! is_array($results)) {
                        break;
                    }

                    [$cursor, $entries] = $results;

                    if (! is_array($entries)) {
                        break;
                    }

                    $entries = array_unique(array_keys($entries));

                    if (count($entries) === 0) {
                        continue;
                    }

                    foreach ($entries as $entry) {
                        yield $entry;
                    }
                } while (((string) $cursor) !== $defaultCursorValue);
            }
        });
    }

    /**
     * Remove the stale entries from the tag set.
     *
     * @return void
     */
    public function flushStaleEntries()
    {
        $flushStaleEntries = function ($pipe) {
            foreach ($this->tagIds() as $tagKey) {
                $pipe->zremrangebyscore($this->store->getPrefix().$tagKey, 0, Carbon::now()->getTimestamp());
            }
        };

        $connection = $this->store->connection();

        if ($connection instanceof PhpRedisConnection) {
            $flushStaleEntries($connection);
        } else {
            $connection->pipeline($flushStaleEntries);
        }
    }

    /**
     * Flush the tag from the cache.
     *
     * @param  string  $name
     * @return string
     */
    public function flushTag($name)
    {
        return $this->resetTag($name);
    }

    /**
     * Reset the tag and return the new tag identifier.
     *
     * @param  string  $name
     * @return string
     */
    public function resetTag($name)
    {
        $this->store->forget($this->tagKey($name));

        return $this->tagId($name);
    }

    /**
     * Get the unique tag identifier for a given tag.
     *
     * @param  string  $name
     * @return string
     */
    public function tagId($name)
    {
        return "tag:{$name}:entries";
    }

    /**
     * Get the tag identifier key for a given tag.
     *
     * @param  string  $name
     * @return string
     */
    public function tagKey($name)
    {
        return "tag:{$name}:entries";
    }
}
