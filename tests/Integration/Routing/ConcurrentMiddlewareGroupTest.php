<?php

namespace Illuminate\Tests\Integration\Routing;

use Illuminate\Contracts\Http\ConcurrentMiddleware;
use Illuminate\Http\Request;
use Illuminate\Routing\ConcurrentMiddlewareGroup;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

class ConcurrentMiddlewareGroupTest extends TestCase
{
    protected function defineRoutes($router)
    {
        $router->concurrentMiddleware('test-group', [
            AddAttributeAlpha::class,
            AddAttributeBeta::class,
        ]);

        $router->aliasMiddleware('concurrent', ConcurrentMiddlewareGroup::class);

        $router->get('/concurrent-test', function (Request $request) {
            return response()->json([
                'alpha' => $request->attributes->get('alpha'),
                'beta' => $request->attributes->get('beta'),
            ]);
        })->middleware('concurrent:test-group');
    }

    public function test_concurrent_middleware_runs_all_before_methods()
    {
        $response = $this->get('/concurrent-test');

        $response->assertOk();
        $response->assertJson([
            'alpha' => 'alpha-value',
            'beta' => 'beta-value',
        ]);
    }

    public function test_concurrent_middleware_merges_request_attributes()
    {
        $response = $this->get('/concurrent-test');

        $data = $response->json();
        $this->assertEquals('alpha-value', $data['alpha']);
        $this->assertEquals('beta-value', $data['beta']);
    }

    public function test_concurrent_middleware_short_circuits_on_response()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('short-circuit', [
            AddAttributeAlpha::class,
            ShortCircuitMiddleware::class,
            AddAttributeBeta::class,
        ]);

        Route::get('/short-circuit', function () {
            return 'should not reach here';
        })->middleware('concurrent:short-circuit');

        $response = $this->get('/short-circuit');

        $response->assertStatus(403);
        $this->assertEquals('blocked', $response->getContent());
    }

    public function test_concurrent_middleware_runs_after_methods()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('with-after', [
            AddResponseHeader::class,
        ]);

        Route::get('/after-test', function () {
            return 'ok';
        })->middleware('concurrent:with-after');

        $response = $this->get('/after-test');

        $response->assertOk();
        $response->assertHeader('X-Custom-Header', 'custom-value');
    }

    public function test_empty_concurrent_group_passes_through()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('empty-group', []);

        Route::get('/empty-group', function () {
            return 'passed through';
        })->middleware('concurrent:empty-group');

        $response = $this->get('/empty-group');

        $response->assertOk();
        $this->assertEquals('passed through', $response->getContent());
    }

    public function test_invalid_middleware_throws_exception()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('invalid-group', [
            NonConcurrentMiddleware::class,
        ]);

        Route::get('/invalid-group', function () {
            return 'should not reach here';
        })->middleware('concurrent:invalid-group');

        $this->withoutExceptionHandling();
        $this->get('/invalid-group');
    }

    public function test_undefined_group_throws_exception()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has not been defined');

        Route::get('/undefined-group', function () {
            return 'should not reach here';
        })->middleware('concurrent:nonexistent');

        $this->withoutExceptionHandling();
        $this->get('/undefined-group');
    }

    public function test_has_concurrent_middleware()
    {
        $router = $this->app->make(Router::class);

        $this->assertTrue($router->hasConcurrentMiddleware('test-group'));
        $this->assertFalse($router->hasConcurrentMiddleware('nonexistent'));
    }
}

class AddAttributeAlpha implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->attributes->set('alpha', 'alpha-value');

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class AddAttributeBeta implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->attributes->set('beta', 'beta-value');

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class ShortCircuitMiddleware implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        return new \Illuminate\Http\Response('blocked', 403);
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class AddResponseHeader implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        $response->headers->set('X-Custom-Header', 'custom-value');

        return $response;
    }
}

class NonConcurrentMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        return $next($request);
    }
}
