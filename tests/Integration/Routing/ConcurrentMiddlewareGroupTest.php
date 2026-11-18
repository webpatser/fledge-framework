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

use function Fledge\Async\delay;

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

    public function test_first_short_circuit_in_registration_order_wins_regardless_of_completion_order()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('ordered-short-circuit', [
            SlowShortCircuitMiddleware::class,
            ShortCircuitMiddleware::class,
        ]);

        Route::get('/ordered-short-circuit', function () {
            return 'should not reach here';
        })->middleware('concurrent:ordered-short-circuit');

        $response = $this->get('/ordered-short-circuit');

        $response->assertStatus(401);
        $this->assertEquals('slow-blocked', $response->getContent());
    }

    public function test_request_merge_is_last_write_wins_in_registration_order()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('ordered-merge', [
            SlowSetSharedAttribute::class,
            FastSetSharedAttribute::class,
        ]);

        Route::get('/ordered-merge', function (Request $request) {
            return response()->json(['shared' => $request->attributes->get('shared')]);
        })->middleware('concurrent:ordered-merge');

        $response = $this->get('/ordered-merge');

        $response->assertOk();
        $this->assertSame('fast', $response->json('shared'));
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

    public function test_concurrent_middleware_propagates_header_removal()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('remove-header', [
            RemoveTrustedHeader::class,
        ]);

        Route::get('/remove-header', function (Request $request) {
            return response()->json(['trusted' => $request->headers->get('X-Trusted')]);
        })->middleware('concurrent:remove-header');

        $response = $this->withHeaders(['X-Trusted' => 'spoofed'])->get('/remove-header');

        $response->assertOk();
        $this->assertNull($response->json('trusted'));
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

    public function test_form_input_merged_by_a_member_reaches_the_controller()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('form-input', [
            MergeInputFlag::class,
            AddAttributeBeta::class,
        ]);

        Route::post('/form-input', function (Request $request) {
            return response()->json(['flag' => $request->input('flag'), 'name' => $request->input('name')]);
        })->middleware('concurrent:form-input');

        $response = $this->post('/form-input', ['name' => 'kept']);

        $response->assertOk();
        $this->assertSame('merged', $response->json('flag'));
        $this->assertSame('kept', $response->json('name'));
    }

    public function test_query_input_changes_and_removals_reach_the_controller()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('query-input', [
            MergeInputFlag::class,
            RemoveDropQueryKey::class,
        ]);

        Route::get('/query-input', function (Request $request) {
            return response()->json([
                'flag' => $request->query('flag'),
                'drop' => $request->query('drop'),
                'keep' => $request->query('keep'),
            ]);
        })->middleware('concurrent:query-input');

        $response = $this->get('/query-input?drop=1&keep=2');

        $response->assertOk();
        $this->assertSame('merged', $response->json('flag'));
        $this->assertNull($response->json('drop'));
        $this->assertSame('2', $response->json('keep'));
    }

    public function test_json_input_is_isolated_per_member_and_merged_back()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('json-input', [
            SlowRecordJsonValue::class,
            MergeJsonValue::class,
            RemoveJsonDropKey::class,
        ]);

        Route::post('/json-input', function (Request $request) {
            return response()->json([
                'seen' => $request->attributes->get('seen'),
                'value' => $request->input('value'),
                'drop' => $request->input('drop'),
                'json_value' => $request->json('value'),
            ]);
        })->middleware('concurrent:json-input');

        $response = $this->postJson('/json-input', ['value' => 'original', 'drop' => 'x']);

        $response->assertOk();
        $this->assertSame('original', $response->json('seen'));
        $this->assertSame('changed', $response->json('value'));
        $this->assertSame('changed', $response->json('json_value'));
        $this->assertNull($response->json('drop'));
    }

    public function test_a_member_that_left_a_header_untouched_does_not_revert_another_members_change()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('untouched-header', [
            ChangeModeHeader::class,
            AddAttributeBeta::class,
        ]);

        Route::get('/untouched-header', function (Request $request) {
            return response()->json(['mode' => $request->headers->get('X-Mode')]);
        })->middleware('concurrent:untouched-header');

        $response = $this->withHeaders(['X-Mode' => 'original'])->get('/untouched-header');

        $response->assertOk();
        $this->assertSame('changed', $response->json('mode'));
    }

    public function test_a_member_that_left_a_response_header_untouched_does_not_revert_another_members_change()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('untouched-response-header', [
            ChangeResponseModeHeader::class,
            AddResponseHeader::class,
        ]);

        Route::get('/untouched-response-header', function () {
            return response('ok')->header('X-Mode', 'original');
        })->middleware('concurrent:untouched-response-header');

        $response = $this->get('/untouched-response-header');

        $response->assertOk();
        $response->assertHeader('X-Mode', 'changed');
        $response->assertHeader('X-Custom-Header', 'custom-value');
    }

    public function test_exception_from_first_registered_member_wins_regardless_of_completion_order()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('ordered-exception', [
            SlowThrowingMiddleware::class,
            FastThrowingMiddleware::class,
        ]);

        Route::get('/ordered-exception', function () {
            return 'should not reach here';
        })->middleware('concurrent:ordered-exception');

        $this->withoutExceptionHandling();

        try {
            $this->get('/ordered-exception');
            $this->fail('Expected the group to rethrow a member exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('slow-failed', $e->getMessage());
        }
    }

    public function test_cookies_added_by_two_members_in_after_both_survive()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('two-cookies', [
            AddCookieA::class,
            AddCookieB::class,
        ]);

        Route::get('/two-cookies', fn () => 'ok')->middleware('concurrent:two-cookies');

        $response = $this->get('/two-cookies');

        $response->assertOk();
        $response->assertPlainCookie('a', '1');
        $response->assertPlainCookie('b', '2');
    }

    public function test_a_cookie_changed_by_one_member_is_not_reverted_by_a_member_that_left_it_alone()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('changed-cookie', [
            ChangeSharedCookie::class,
            AddCookieB::class,
        ]);

        Route::get('/changed-cookie', fn () => response('ok')->cookie('shared', 'original'))
            ->middleware('concurrent:changed-cookie');

        $response = $this->get('/changed-cookie');

        $response->assertOk();
        $response->assertPlainCookie('shared', 'changed');
        $response->assertPlainCookie('b', '2');
    }

    public function test_a_cookie_removed_by_one_member_stays_removed()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('removed-cookie', [
            RemoveDropCookie::class,
            AddCookieB::class,
        ]);

        Route::get('/removed-cookie', fn () => response('ok')->cookie('drop', 'x')->cookie('keep', 'y'))
            ->middleware('concurrent:removed-cookie');

        $response = $this->get('/removed-cookie');

        $response->assertOk();
        $response->assertCookieMissing('drop');
        $response->assertPlainCookie('keep', 'y');
        $response->assertPlainCookie('b', '2');
    }

    public function test_cookie_merge_does_not_depend_on_the_clock_moving_between_members()
    {
        $now = \Illuminate\Support\Carbon::parse('2026-01-01 12:00:00');
        \Illuminate\Support\Carbon::setTestNow($now);

        try {
            $router = $this->app->make(Router::class);
            $router->concurrentMiddleware('clock-cookies', [
                AddExpiringCookieA::class,
                AdvanceClockThenAddExpiringCookieB::class,
            ]);

            Route::get('/clock-cookies', fn () => response('ok')->cookie('base', 'z', 10))
                ->middleware('concurrent:clock-cookies');

            $response = $this->get('/clock-cookies');

            $response->assertOk();
            $response->assertPlainCookie('base', 'z');
            $this->assertSame($now->getTimestamp() + 600, $response->getCookie('base', false)->getExpiresTime());
            $this->assertSame($now->getTimestamp() + 600, $response->getCookie('a', false)->getExpiresTime());
            $this->assertSame($now->getTimestamp() + 601, $response->getCookie('b', false)->getExpiresTime());
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_request_cookie_changes_reach_the_controller()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('request-cookies', [
            RewriteRequestCookie::class,
            AddAttributeBeta::class,
        ]);

        Route::get('/request-cookies', fn (Request $request) => response()->json([
            'pref' => $request->cookie('pref'),
            'gone' => $request->cookie('gone'),
        ]))->middleware('concurrent:request-cookies');

        $response = $this->withUnencryptedCookies(['pref' => 'raw', 'gone' => 'x'])->get('/request-cookies');

        $response->assertOk();
        $this->assertSame('decoded', $response->json('pref'));
        $this->assertNull($response->json('gone'));
    }

    public function test_request_server_and_file_changes_reach_the_controller()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('server-files', [
            SetServerFlag::class,
            RemoveUploadedDoc::class,
        ]);

        Route::post('/server-files', fn (Request $request) => response()->json([
            'flag' => $request->server('FLEDGE_FLAG'),
            'doc' => $request->hasFile('doc'),
            'other' => $request->hasFile('other'),
        ]))->middleware('concurrent:server-files');

        $response = $this->post('/server-files', [
            'doc' => \Illuminate\Http\UploadedFile::fake()->create('doc.txt', 1),
            'other' => \Illuminate\Http\UploadedFile::fake()->create('other.txt', 1),
        ]);

        $response->assertOk();
        $this->assertSame('on', $response->json('flag'));
        $this->assertFalse($response->json('doc'));
        $this->assertTrue($response->json('other'));
    }

    public function test_user_and_route_resolvers_and_session_set_by_members_reach_the_controller()
    {
        RecordingRouteResolver::$called = false;

        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('resolvers', [
            SetUserResolver::class,
            WrapRouteResolver::class,
            SetSessionStore::class,
        ]);

        Route::get('/resolvers', function (Request $request) {
            $request->route();

            return response()->json([
                'user' => $request->user(),
                'route_resolver_used' => RecordingRouteResolver::$called,
                'session' => $request->hasSession() ? $request->session()->get('k') : null,
            ]);
        })->middleware('concurrent:resolvers');

        $response = $this->get('/resolvers');

        $response->assertOk();
        $this->assertSame('alice', $response->json('user'));
        $this->assertTrue($response->json('route_resolver_used'));
        $this->assertSame('v', $response->json('session'));
    }

    public function test_nested_input_sub_keys_changed_by_different_members_are_both_kept()
    {
        $router = $this->app->make(Router::class);
        $router->concurrentMiddleware('nested-input', [
            MergeNestedFilterA::class,
            MergeNestedFilterB::class,
            ReplaceTagsList::class,
        ]);

        Route::post('/nested-input', fn (Request $request) => response()->json([
            'filters' => $request->input('filters'),
            'tags' => $request->input('tags'),
        ]))->middleware('concurrent:nested-input');

        $response = $this->post('/nested-input', [
            'filters' => ['a' => '1', 'b' => '2', 'c' => '3'],
            'tags' => ['x', 'y'],
        ]);

        $response->assertOk();
        $this->assertSame(['a' => 'A', 'b' => 'B', 'c' => '3'], $response->json('filters'));
        $this->assertSame(['z'], $response->json('tags'));
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

class SlowShortCircuitMiddleware implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        delay(0.05);

        return new \Illuminate\Http\Response('slow-blocked', 401);
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class SlowSetSharedAttribute implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        delay(0.05);

        $request->attributes->set('shared', 'slow');

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class FastSetSharedAttribute implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->attributes->set('shared', 'fast');

        return $request;
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

class RemoveTrustedHeader implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->headers->remove('X-Trusted');

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class MergeInputFlag implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->merge(['flag' => 'merged']);

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class RemoveDropQueryKey implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->query->remove('drop');

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class SlowRecordJsonValue implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        delay(0.05);

        $request->attributes->set('seen', $request->input('value'));

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class MergeJsonValue implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->merge(['value' => 'changed']);

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class RemoveJsonDropKey implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->offsetUnset('drop');

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class ChangeModeHeader implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->headers->set('X-Mode', 'changed');

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class ChangeResponseModeHeader implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        $response->headers->set('X-Mode', 'changed');

        return $response;
    }
}

class SlowThrowingMiddleware implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        delay(0.05);

        throw new \RuntimeException('slow-failed');
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class FastThrowingMiddleware implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        throw new \RuntimeException('fast-failed');
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

abstract class PassThroughConcurrentMiddleware implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class AddCookieA extends PassThroughConcurrentMiddleware
{
    public function after(Request $request, Response $response): Response
    {
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('a', '1'));

        return $response;
    }
}

class AddCookieB extends PassThroughConcurrentMiddleware
{
    public function after(Request $request, Response $response): Response
    {
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('b', '2'));

        return $response;
    }
}

class ChangeSharedCookie extends PassThroughConcurrentMiddleware
{
    public function after(Request $request, Response $response): Response
    {
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('shared', 'changed'));

        return $response;
    }
}

class RemoveDropCookie extends PassThroughConcurrentMiddleware
{
    public function after(Request $request, Response $response): Response
    {
        $response->headers->removeCookie('drop');

        return $response;
    }
}

class AddExpiringCookieA extends PassThroughConcurrentMiddleware
{
    public function after(Request $request, Response $response): Response
    {
        $response->headers->setCookie(cookie('a', '1', 10));

        return $response;
    }
}

class AdvanceClockThenAddExpiringCookieB extends PassThroughConcurrentMiddleware
{
    public function after(Request $request, Response $response): Response
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSecond());

        $response->headers->setCookie(cookie('b', '2', 10));

        return $response;
    }
}

class RewriteRequestCookie extends PassThroughConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->cookies->set('pref', 'decoded');
        $request->cookies->remove('gone');

        return $request;
    }
}

class SetServerFlag extends PassThroughConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->server->set('FLEDGE_FLAG', 'on');

        return $request;
    }
}

class RemoveUploadedDoc extends PassThroughConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->files->remove('doc');

        return $request;
    }
}

class SetUserResolver extends PassThroughConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->setUserResolver(fn () => 'alice');

        return $request;
    }
}

class RecordingRouteResolver
{
    public static bool $called = false;
}

class WrapRouteResolver extends PassThroughConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $inner = $request->getRouteResolver();

        $request->setRouteResolver(function () use ($inner) {
            RecordingRouteResolver::$called = true;

            return $inner();
        });

        return $request;
    }
}

class SetSessionStore extends PassThroughConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $store = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10));
        $store->put('k', 'v');

        $request->setLaravelSession($store);

        return $request;
    }
}

class MergeNestedFilterA extends PassThroughConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->merge(['filters.a' => 'A']);

        return $request;
    }
}

class MergeNestedFilterB extends PassThroughConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->merge(['filters.b' => 'B']);

        return $request;
    }
}

class ReplaceTagsList extends PassThroughConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        $request->merge(['tags' => ['z']]);

        return $request;
    }
}

class NonConcurrentMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        return $next($request);
    }
}
