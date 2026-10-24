<?php

namespace Illuminate\Tests\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\PersistentCurlShareManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HttpClientPersistentConnectionsTest extends TestCase
{
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
}
