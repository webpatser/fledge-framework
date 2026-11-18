<?php

namespace Illuminate\Tests\Http;

use Closure;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransportSharing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\PersistentCurlShareManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionObject;

class HttpClientPersistentConnectionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::flushState();

        parent::tearDown();
    }

    public function testFactoryRejectsCookieLockData()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CURL_LOCK_DATA_COOKIE');

        (new Factory)->persistentConnections(true, [CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_COOKIE]);
    }

    public function testFactoryDoesNotEnablePersistentConnectionsWhenOptionsAreRejected()
    {
        $factory = new Factory;

        try {
            $factory->persistentConnections(true, [CURL_LOCK_DATA_COOKIE]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertFalse((fn () => $this->usePersistentConnections)->call($factory));
    }

    public function testPendingRequestRejectsCookieLockData()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CURL_LOCK_DATA_COOKIE');

        (new PendingRequest)->withPersistentConnections([CURL_LOCK_DATA_COOKIE]);
    }

    public function testShareManagerRejectsCookieLockData()
    {
        $this->expectException(InvalidArgumentException::class);

        PersistentCurlShareManager::getHandle([CURL_LOCK_DATA_CONNECT, CURL_LOCK_DATA_COOKIE]);
    }

    public function testSafeOptionsAreAccepted()
    {
        $factory = (new Factory)->persistentConnections(true, [CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_CONNECT]);
        $request = (new PendingRequest)->withPersistentConnections([CURL_LOCK_DATA_DNS]);

        $this->assertInstanceOf(Factory::class, $factory);
        $this->assertInstanceOf(PendingRequest::class, $request);

        PersistentCurlShareManager::assertSafeOptions([CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_CONNECT]);
    }

    public function testPersistentRequestGoesThroughTheRealCurlHandler()
    {
        $this->expectException(ConnectionException::class);

        (new PendingRequest)
            ->withPersistentConnections()
            ->connectTimeout(1)
            ->get('http://127.0.0.1:1/');
    }

    public function testFactoryPersistentRequestGoesThroughTheRealCurlHandler()
    {
        $this->expectException(ConnectionException::class);

        (new Factory)->persistentConnections()->connectTimeout(1)->get('http://127.0.0.1:1/');
    }

    public function testGuzzle8HandlerUsesPersistentTransportSharing()
    {
        $this->requireGuzzle8();

        $states = $this->shareStates((new PendingRequest)->withPersistentConnections()->buildHandlerStack());

        $this->assertNotEmpty($states);

        foreach ($states as $state) {
            $this->assertSame(TransportSharing::PERSISTENT_PREFER, $state->mode);
            $this->assertInstanceOf(\CurlSharePersistentHandle::class, $state->handle);
        }
    }

    public function testGuzzle8FactoryHandlerUsesPersistentTransportSharing()
    {
        $this->requireGuzzle8();

        $request = (fn () => $this->newPendingRequest())->call((new Factory)->persistentConnections());

        $states = $this->shareStates($request->buildHandlerStack());

        $this->assertNotEmpty($states);
        $this->assertInstanceOf(\CurlSharePersistentHandle::class, $states[0]->handle);
    }

    public function testGuzzle8HandlerWithoutPersistentConnectionsSharesNothing()
    {
        $this->requireGuzzle8();

        $this->assertSame([], $this->shareStates((new PendingRequest)->buildHandlerStack()));
    }

    public function testGuzzle8CallerTransportSharingWins()
    {
        $this->requireGuzzle8();

        $stack = (new PendingRequest)
            ->withOptions(['transport_sharing' => TransportSharing::NONE])
            ->withPersistentConnections()
            ->buildHandlerStack();

        $this->assertSame([], $this->shareStates($stack));
    }

    public function testGuzzle8DoesNotPassShareHandleInCurlOption()
    {
        $this->requireGuzzle8();

        $seen = [];

        (new PendingRequest)
            ->withPersistentConnections()
            ->withOptions(['curl' => [CURLOPT_TCP_NODELAY => true]])
            ->setHandler($this->recordingHandler($seen))
            ->get('http://example.test');

        $this->assertCount(1, $seen);
        $this->assertArrayNotHasKey(CURLOPT_SHARE, $seen[0]['curl']);
        $this->assertTrue($seen[0]['curl'][CURLOPT_TCP_NODELAY]);
    }

    public function testCustomHandlerIsLeftAlone()
    {
        $seen = [];

        Factory::globalHandler($this->recordingHandler($seen));

        $factory = (new Factory)->persistentConnections();
        $factory->get('http://example.test/one');
        $factory->get('http://example.test/two');

        $this->assertCount(2, $seen);
    }

    public function testFactoryWithoutPersistentConnectionsSendsNoShareHandle()
    {
        $seen = [];

        Factory::globalHandler($this->recordingHandler($seen));

        (new Factory)->get('http://example.test');

        $this->assertCount(1, $seen);
        $this->assertArrayNotHasKey('curl', $seen[0]);
    }

    public function testGuzzle7PassesThePersistentShareHandleInTheCurlOption()
    {
        $this->requireGuzzle7();

        $seen = [];

        $request = (new PendingRequest)
            ->withPersistentConnections([CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_CONNECT])
            ->setHandler($this->recordingHandler($seen));

        $request->get('http://example.test/one');
        $request->get('http://example.test/two');

        $handle = PersistentCurlShareManager::getHandle([CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_CONNECT]);

        $this->assertCount(2, $seen);

        foreach ($seen as $options) {
            $this->assertSame($handle, $options['curl'][CURLOPT_SHARE] ?? null);
        }
    }

    public function testGuzzle7KeepsUserCurlOptions()
    {
        $this->requireGuzzle7();

        $seen = [];
        $ownShare = curl_share_init();

        (new PendingRequest)
            ->withOptions(['curl' => [CURLOPT_SHARE => $ownShare, CURLOPT_TCP_NODELAY => true]])
            ->withPersistentConnections()
            ->setHandler($this->recordingHandler($seen))
            ->get('http://example.test');

        $this->assertSame($ownShare, $seen[0]['curl'][CURLOPT_SHARE]);
        $this->assertTrue($seen[0]['curl'][CURLOPT_TCP_NODELAY]);
    }

    public function testGuzzle7CallingWithPersistentConnectionsAgainSwitchesTheHandle()
    {
        $this->requireGuzzle7();

        $seen = [];

        (new PendingRequest)
            ->withPersistentConnections([CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_CONNECT])
            ->withPersistentConnections([CURL_LOCK_DATA_DNS])
            ->setHandler($this->recordingHandler($seen))
            ->get('http://example.test');

        $this->assertSame(PersistentCurlShareManager::getHandle([CURL_LOCK_DATA_DNS]), $seen[0]['curl'][CURLOPT_SHARE]);
    }

    protected function requireGuzzle8(): void
    {
        if (! class_exists(TransportSharing::class)) {
            $this->markTestSkipped('Requires Guzzle 8 transport sharing.');
        }
    }

    protected function requireGuzzle7(): void
    {
        if (class_exists(TransportSharing::class)) {
            $this->markTestSkipped('Guzzle 8 rejects CURLOPT_SHARE in the "curl" request option.');
        }
    }

    /**
     * A Guzzle handler that records the request options it receives.
     */
    protected function recordingHandler(array &$seen): callable
    {
        return function ($request, array $options) use (&$seen) {
            $seen[] = $options;

            return new FulfilledPromise(new Psr7Response(200));
        };
    }

    /**
     * Collect every Guzzle share handle state reachable from the given value.
     *
     * @return list<\GuzzleHttp\Handler\CurlShareHandleState>
     */
    protected function shareStates(mixed $value, array &$visited = [], int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        if ($value instanceof CurlShareHandleState) {
            return [$value];
        }

        $children = [];

        if (is_array($value)) {
            $children = $value;
        } elseif ($value instanceof Closure) {
            $children = (new ReflectionFunction($value))->getClosureUsedVariables();
        } elseif (is_object($value)) {
            $id = spl_object_id($value);

            if (isset($visited[$id])) {
                return [];
            }

            $visited[$id] = true;

            for ($reflection = new ReflectionObject($value); $reflection; $reflection = $reflection->getParentClass()) {
                foreach ($reflection->getProperties() as $property) {
                    if (! $property->isStatic() && $property->isInitialized($value)) {
                        $children[] = $property->getValue($value);
                    }
                }
            }
        }

        $states = [];

        foreach ($children as $child) {
            array_push($states, ...$this->shareStates($child, $visited, $depth + 1));
        }

        return $states;
    }
}
