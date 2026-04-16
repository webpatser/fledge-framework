<?php

namespace Illuminate\Routing;

use Closure;
use Illuminate\Concurrency\FiberDriver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\ConcurrentMiddleware;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ConcurrentMiddlewareGroup
{
    public function __construct(
        protected Container $container,
        protected Router $router,
    ) {}

    /**
     * Handle the request by running all group members concurrently.
     */
    public function handle(Request $request, Closure $next, string $groupName): Response
    {
        $middlewareClasses = $this->router->getConcurrentMiddleware($groupName);

        if (empty($middlewareClasses)) {
            return $next($request);
        }

        $middlewares = $this->resolveMiddleware($middlewareClasses);

        // Run all before() methods concurrently, each with a cloned request.
        $original = clone $request;
        $results = $this->runConcurrently(
            array_map(fn (ConcurrentMiddleware $m) => fn () => $m->before(clone $original), $middlewares)
        );

        // Check for short-circuit responses (first Response in registration order wins).
        foreach ($results as $result) {
            if ($result instanceof Response) {
                return $result;
            }
        }

        // Merge request modifications from all middleware.
        $mergedRequest = $this->mergeRequests($request, $results);

        // Continue down the pipeline.
        $response = $next($mergedRequest);

        // Run all after() methods concurrently.
        $afterResults = $this->runConcurrently(
            array_map(fn (ConcurrentMiddleware $m) => fn () => $m->after($mergedRequest, clone $response), $middlewares)
        );

        // Merge response modifications.
        return $this->mergeResponses($response, $afterResults);
    }

    /**
     * Resolve and validate all middleware instances.
     *
     * @return array<int, ConcurrentMiddleware>
     */
    protected function resolveMiddleware(array $classes): array
    {
        $middlewares = [];

        foreach ($classes as $class) {
            $instance = $this->container->make($class);

            if (! $instance instanceof ConcurrentMiddleware) {
                throw new InvalidArgumentException(
                    "Middleware [{$class}] must implement ".ConcurrentMiddleware::class.' to be used in a concurrent group.'
                );
            }

            $middlewares[] = $instance;
        }

        return $middlewares;
    }

    /**
     * Run tasks concurrently using the FiberDriver, falling back to sequential execution.
     */
    protected function runConcurrently(array $tasks): array
    {
        if (class_exists(FiberDriver::class)) {
            return (new FiberDriver)->run($tasks);
        }

        // Sequential fallback when FiberDriver is not available.
        return array_map(fn (Closure $task) => $task(), $tasks);
    }

    /**
     * Merge modified requests back into the original.
     *
     * Applies attribute and header changes in registration order (last-write-wins).
     */
    protected function mergeRequests(Request $request, array $results): Request
    {
        foreach ($results as $modified) {
            if (! $modified instanceof Request) {
                continue;
            }

            // Merge attributes (request->attributes is a ParameterBag).
            foreach ($modified->attributes->all() as $key => $value) {
                $request->attributes->set($key, $value);
            }

            // Merge added/modified headers.
            foreach ($modified->headers->all() as $key => $values) {
                if ($modified->headers->get($key) !== $request->headers->get($key)) {
                    $request->headers->set($key, $values);
                }
            }
        }

        return $request;
    }

    /**
     * Merge modified responses back into the original.
     *
     * Applies header additions in registration order.
     */
    protected function mergeResponses(Response $response, array $results): Response
    {
        foreach ($results as $modified) {
            if (! $modified instanceof Response) {
                continue;
            }

            // Merge added/modified headers.
            foreach ($modified->headers->all() as $key => $values) {
                if ($modified->headers->get($key) !== $response->headers->get($key)) {
                    $response->headers->set($key, $values);
                }
            }
        }

        return $response;
    }
}
