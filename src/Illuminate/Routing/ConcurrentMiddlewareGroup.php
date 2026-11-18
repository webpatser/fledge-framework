<?php

namespace Illuminate\Routing;

use Closure;
use Illuminate\Concurrency\FiberDriver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\ConcurrentMiddleware;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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
     *
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next, string $groupName): Response
    {
        $middlewareClasses = $this->router->getConcurrentMiddleware($groupName);

        if (empty($middlewareClasses)) {
            return $next($request);
        }

        $middlewares = $this->resolveMiddleware($middlewareClasses);

        // Run all before() methods concurrently, each with an isolated clone of the request.
        $original = $this->isolatedClone($request);
        $results = $this->runConcurrently(
            array_map(fn (ConcurrentMiddleware $m) => fn () => $m->before($this->isolatedClone($original)), $middlewares)
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
     * @param  array<int, string>  $classes
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
     *
     * @param  array<array-key, \Closure(): mixed>  $tasks
     * @return array<array-key, mixed>
     */
    protected function runConcurrently(array $tasks): array
    {
        if (class_exists(FiberDriver::class)) {
            return (new FiberDriver)->runWithoutConnectionScope($tasks);
        }

        // Sequential fallback when FiberDriver is not available.
        return array_map(fn (Closure $task) => $task(), $tasks);
    }

    /**
     * Clone a request so a concurrent member cannot reach shared input state.
     *
     * Symfony's __clone() copies the query, request, attribute and header bags
     * but not Laravel's lazily decoded JSON bag, so every clone would share one
     * InputBag and see other members' merge()/replace() calls mid-flight. For a
     * JSON request the JSON bag is given to the clone as its own copy, keeping
     * the "json bag is the request bag" link Request::createFromBase() sets up.
     */
    protected function isolatedClone(Request $request): Request
    {
        $clone = clone $request;

        if ($request->isJson()) {
            $clone->setJson($request->json() === $request->request ? $clone->request : clone $request->json());
        }

        return $clone;
    }

    /**
     * Merge modified requests back into the original.
     *
     * Each member's result is diffed against $original (the exact snapshot every
     * member received), and only what that member changed is applied, in
     * registration order (later registration wins). This covers attributes,
     * headers, cookies, files, server values, input (query, request body and
     * JSON body), and the user resolver, route resolver and session store. A
     * member that left a value untouched never reverts another member's change.
     *
     * @param  array<array-key, mixed>  $results
     */
    protected function mergeRequests(Request $request, Request $original, array $results): Request
    {
        $baselineState = $this->requestState($original);

        foreach ($results as $modified) {
            if (! $modified instanceof Request) {
                continue;
            }

            $this->mergeBag($request->attributes, $original->attributes->all(), $modified->attributes->all());
            $this->mergeBag($request->cookies, $original->cookies->all(), $modified->cookies->all());
            $this->mergeBag($request->server, $original->server->all(), $modified->server->all());

            if ($this->mergeBag($request->files, $original->files->all(), $modified->files->all())) {
                // Laravel caches the converted UploadedFile tree; drop it so file() sees the merge.
                (fn () => $this->convertedFiles = null)->call($request);
            }

            $targets = $this->inputBags($request);
            $baselines = $this->inputBags($original);

            foreach ($this->inputBags($modified) as $name => $bag) {
                if (isset($targets[$name], $baselines[$name])) {
                    $this->mergeBag($targets[$name], $baselines[$name]->all(), $bag->all());
                }
            }

            $this->mergeHeaders($request->headers, $original->headers, $modified->headers);

            $state = $this->requestState($modified);

            if ($state['userResolver'] !== null && $state['userResolver'] !== $baselineState['userResolver']) {
                $request->setUserResolver($state['userResolver']);
            }

            if ($state['routeResolver'] !== null && $state['routeResolver'] !== $baselineState['routeResolver']) {
                $request->setRouteResolver($state['routeResolver']);
            }

            if ($state['session'] !== null && $state['session'] !== $baselineState['session']) {
                $request->setLaravelSession($state['session']);
            }
        }

        return $request;
    }

    /**
     * Read the resolver and session state a member may have replaced.
     *
     * The raw resolver properties are read because getUserResolver() returns a
     * fresh no-op closure when none is set, which would never compare equal.
     *
     * @return array{userResolver: ?\Closure, routeResolver: ?\Closure, session: ?\Illuminate\Contracts\Session\Session}
     */
    protected function requestState(Request $request): array
    {
        return (fn () => [
            'userResolver' => $this->userResolver,
            'routeResolver' => $this->routeResolver,
            'session' => $this->hasSession() ? $this->session() : null,
        ])->call($request);
    }

    /**
     * Get the input bags of a request, keyed by name.
     *
     * The JSON bag is listed separately only when it is not the request bag
     * itself, so a JSON request built by createFromBase() is merged once.
     *
     * @return array<string, ParameterBag>
     */
    protected function inputBags(Request $request): array
    {
        $bags = ['query' => $request->query, 'request' => $request->request];

        if ($request->isJson() && $request->json() !== $request->request) {
            $bags['json'] = $request->json();
        }

        return $bags;
    }

    /**
     * Apply the keys a member added, changed or removed relative to the baseline.
     *
     * Returns whether the target bag was changed.
     *
     * @param  array<array-key, mixed>  $baseline
     * @param  array<array-key, mixed>  $modified
     */
    protected function mergeBag(ParameterBag $target, array $baseline, array $modified): bool
    {
        $current = $target->all();
        $merged = $this->mergeArray($current, $baseline, $modified);
        $changed = false;

        foreach ($merged as $key => $value) {
            if (! array_key_exists($key, $current) || $current[$key] !== $value) {
                $target->set($key, $value);
                $changed = true;
            }
        }

        foreach ($current as $key => $value) {
            if (! array_key_exists($key, $merged)) {
                $target->remove($key);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Apply one member's changes (modified versus baseline) onto the target array.
     *
     * Associative arrays are merged recursively, so a member's changed leaf wins
     * and a leaf it left untouched never reverts another member's change. Lists
     * and scalar values are replaced as a whole.
     *
     * @param  array<array-key, mixed>  $target
     * @param  array<array-key, mixed>  $baseline
     * @param  array<array-key, mixed>  $modified
     * @return array<array-key, mixed>
     */
    protected function mergeArray(array $target, array $baseline, array $modified): array
    {
        foreach ($modified as $key => $value) {
            if (! array_key_exists($key, $baseline)) {
                $target[$key] = $value;

                continue;
            }

            $base = $baseline[$key];

            if ($base === $value) {
                continue;
            }

            if ($this->isAssoc($base) && $this->isAssoc($value) && is_array($target[$key] ?? null)) {
                $target[$key] = $this->mergeArray($target[$key], $base, $value);
            } else {
                $target[$key] = $value;
            }
        }

        foreach ($baseline as $key => $value) {
            if (! array_key_exists($key, $modified)) {
                unset($target[$key]);
            }
        }

        return $target;
    }

    /**
     * Determine if the value is a non-empty associative array.
     */
    protected function isAssoc(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    /**
     * Apply the headers a member added, changed or removed relative to the baseline.
     *
     * Response cookies are merged as Cookie objects by mergeCookies(), so the
     * rendered set-cookie line (whose Max-Age depends on the current time) is
     * left out here.
     */
    protected function mergeHeaders(HeaderBag $target, HeaderBag $baseline, HeaderBag $modified): void
    {
        $skip = $target instanceof ResponseHeaderBag ? 'set-cookie' : null;

        foreach ($modified->all() as $key => $values) {
            if ($key !== $skip && (! $baseline->has($key) || $baseline->all($key) !== $values)) {
                $target->set($key, $values);
            }
        }

        foreach ($baseline->all() as $key => $values) {
            if ($key !== $skip && ! $modified->has($key)) {
                $target->remove($key);
            }
        }

        if ($target instanceof ResponseHeaderBag && $baseline instanceof ResponseHeaderBag && $modified instanceof ResponseHeaderBag) {
            $this->mergeCookies($target, $baseline, $modified);
        }
    }

    /**
     * Apply the cookies a member added, changed or removed relative to the baseline.
     *
     * Cookies are keyed by domain, path and name and compared by value, never by
     * their rendered header line. A cookie a member cleared with clearCookie() is
     * an expired cookie and so arrives here as a change.
     */
    protected function mergeCookies(ResponseHeaderBag $target, ResponseHeaderBag $baseline, ResponseHeaderBag $modified): void
    {
        $before = $this->cookiesByKey($baseline);
        $after = $this->cookiesByKey($modified);

        foreach ($after as $key => $cookie) {
            if (! isset($before[$key]) || $before[$key] != $cookie) {
                $target->setCookie($cookie);
            }
        }

        foreach ($before as $key => $cookie) {
            if (! isset($after[$key])) {
                $target->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }
    }

    /**
     * Index a header bag's cookies by domain, path and name.
     *
     * @return array<string, Cookie>
     */
    protected function cookiesByKey(ResponseHeaderBag $headers): array
    {
        $cookies = [];

        foreach ($headers->getCookies() as $cookie) {
            $cookies[$cookie->getDomain()."\0".$cookie->getPath()."\0".$cookie->getName()] = $cookie;
        }

        return $cookies;
    }

    /**
     * Merge modified responses back into the original.
     *
     * Each member's headers are diffed against $original (the pristine snapshot
     * every member received) and only what that member added, changed or
     * removed is applied, in registration order. A member that left a header
     * untouched never reverts another member's change to it.
     *
     * @param  array<array-key, mixed>  $results
     */
    protected function mergeResponses(Response $response, Response $original, array $results): Response
    {
        foreach ($results as $modified) {
            if (! $modified instanceof Response) {
                continue;
            }

            $this->mergeHeaders($response->headers, $original->headers, $modified->headers);
        }

        return $response;
    }
}
