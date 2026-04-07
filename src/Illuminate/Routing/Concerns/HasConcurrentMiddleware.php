<?php

namespace Illuminate\Routing\Concerns;

use InvalidArgumentException;

trait HasConcurrentMiddleware
{
    /**
     * The registered concurrent middleware groups.
     *
     * @var array<string, array<int, string>>
     */
    protected array $concurrentMiddleware = [];

    /**
     * Register a concurrent middleware group.
     *
     * @param  string  $name
     * @param  array<int, string>  $middleware
     * @return $this
     */
    public function concurrentMiddleware(string $name, array $middleware): static
    {
        $this->concurrentMiddleware[$name] = $middleware;

        return $this;
    }

    /**
     * Get the middleware classes for a concurrent middleware group.
     *
     * @param  string  $name
     * @return array<int, string>
     *
     * @throws \InvalidArgumentException
     */
    public function getConcurrentMiddleware(string $name): array
    {
        if (! isset($this->concurrentMiddleware[$name])) {
            throw new InvalidArgumentException("Concurrent middleware group [{$name}] has not been defined.");
        }

        return $this->concurrentMiddleware[$name];
    }

    /**
     * Determine if a concurrent middleware group exists.
     */
    public function hasConcurrentMiddleware(string $name): bool
    {
        return isset($this->concurrentMiddleware[$name]);
    }
}
