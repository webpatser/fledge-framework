<?php

namespace Illuminate\Tests\Http;

use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

class HttpClientGlobalHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::flushState();

        parent::tearDown();
    }

    public function testFlushStateClearsTheGlobalHandler()
    {
        Factory::globalHandler(fn () => null);
        $this->assertIsCallable(Factory::getGlobalHandler());

        Factory::flushState();

        $this->assertNull(Factory::getGlobalHandler());
    }

    public function testFlushStateIsSafeWhenNoGlobalHandlerIsSet()
    {
        Factory::flushState();
        Factory::flushState();

        $this->assertNull(Factory::getGlobalHandler());
    }

    public function testGlobalHandlerIsReturnedUntilFlushed()
    {
        $handler = fn () => null;

        Factory::globalHandler($handler);

        $this->assertSame($handler, Factory::getGlobalHandler());

        Factory::flushState();

        $this->assertNull(Factory::getGlobalHandler());
    }
}
