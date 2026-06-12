<?php

namespace Illuminate\Routing;

use Closure;
use Illuminate\Concurrency\FiberDriver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\ConcurrentMiddleware;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs a group of middleware concurrently, each receiving its own clone of the
 * request, then merges their modifications back together.
 *
 * Security note: every member's before() runs *before* a short-circuit response
 * is selected, so authentication / authorization middleware MUST NOT be placed
 * in a concurrent group. Their side effects (session writes, audit logging,
 * "remember me" rotation, etc.) would run even for requests that another member
 * rejects. Auth middleware also depends on running first and aborting the
 * pipeline, which concurrency cannot guarantee. resolveMiddleware() rejects the
 * framework's auth middleware at registration time; custom auth middleware
 * should likewise be kept out of concurrent groups.
 */
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

        // Merge request modifications from all middleware. The pristine $original
        // is the baseline each middleware was handed, so we can detect removals.
        $mergedRequest = $this->mergeRequests($request, $original, $results);

        // Continue down the pipeline.
        $response = $next($mergedRequest);

        // Snapshot the pristine response so after() removals can be detected too.
        $originalResponse = clone $response;

        // Run all after() methods concurrently.
        $afterResults = $this->runConcurrently(
            array_map(fn (ConcurrentMiddleware $m) => fn () => $m->after($mergedRequest, clone $response), $middlewares)
        );

        // Merge response modifications.
        return $this->mergeResponses($response, $originalResponse, $afterResults);
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

            if ($this->isAuthMiddleware($instance)) {
                throw new InvalidArgumentException(
                    "Middleware [{$class}] performs authentication or authorization and must not be used in a "
                    .'concurrent group: its before() side effects would run even for requests rejected by another '
                    .'member, and concurrency cannot guarantee it runs first or aborts the pipeline.'
                );
            }

            $middlewares[] = $instance;
        }

        return $middlewares;
    }

    /**
     * Determine if the given middleware performs authentication or authorization.
     *
     * Auth middleware must short-circuit the pipeline deterministically and runs
     * side effects that must not execute for rejected requests, so it cannot be
     * safely placed in a concurrent group.
     */
    protected function isAuthMiddleware(object $instance): bool
    {
        return str_starts_with($instance::class, 'Illuminate\\Auth\\Middleware\\');
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
     * Removals are propagated too: a key present in the pristine $original that a
     * middleware dropped from its result is unset on the merged request. The diff
     * is taken against $original (the exact clone each middleware received) so a
     * key is only treated as removed when that middleware actually removed it, not
     * when a different concurrent member simply never touched it.
     */
    protected function mergeRequests(Request $request, Request $original, array $results): Request
    {
        foreach ($results as $modified) {
            if (! $modified instanceof Request) {
                continue;
            }

            // Merge attributes (request->attributes is a ParameterBag).
            foreach ($modified->attributes->all() as $key => $value) {
                $request->attributes->set($key, $value);
            }

            // Propagate attribute removals made by this middleware.
            foreach ($original->attributes->all() as $key => $value) {
                if (! $modified->attributes->has($key)) {
                    $request->attributes->remove($key);
                }
            }

            // Merge added/modified headers.
            foreach ($modified->headers->all() as $key => $values) {
                if ($modified->headers->get($key) !== $request->headers->get($key)) {
                    $request->headers->set($key, $values);
                }
            }

            // Propagate header removals made by this middleware.
            foreach ($original->headers->all() as $key => $values) {
                if (! $modified->headers->has($key)) {
                    $request->headers->remove($key);
                }
            }
        }

        return $request;
    }

    /**
     * Merge modified responses back into the original.
     *
     * Applies header additions in registration order, and propagates header
     * removals: a header present in the pristine $original that a middleware
     * dropped is unset on the merged response. The diff is taken against the
     * clone each middleware received so a header is only treated as removed when
     * that middleware actually removed it.
     */
    protected function mergeResponses(Response $response, Response $original, array $results): Response
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

            // Propagate header removals made by this middleware.
            foreach ($original->headers->all() as $key => $values) {
                if (! $modified->headers->has($key)) {
                    $response->headers->remove($key);
                }
            }
        }

        return $response;
    }
}
