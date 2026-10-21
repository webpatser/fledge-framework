<?php

namespace Illuminate\Tests\Pipeline;

use ErrorException;
use Illuminate\Container\Container;
use Illuminate\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

/**
 * Pipeline::send(), through(), pipe() and via() implement the pipeline
 * contract and are mutating fluent setters, so third-party code may call them
 * as bare statements. They must not carry #[\NoDiscard], or PHP 8.5 raises a
 * warning that the framework's error handler turns into an ErrorException.
 */
class PipelineNoDiscardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        set_error_handler(function (int $level, string $message, string $file, int $line) {
            throw new ErrorException($message, 0, $level, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();

        parent::tearDown();
    }

    public function testFluentSettersCanBeCalledAsBareStatements(): void
    {
        $pipeline = new Pipeline(new Container);

        $pipeline->send('foo');
        $pipeline->through([PipelineNoDiscardTestPipe::class]);
        $pipeline->pipe(PipelineNoDiscardTestPipe::class);
        $pipeline->via('handle');

        $this->assertSame('foo|piped|piped', $pipeline->thenReturn());
    }

    public function testFluentSettersCanBeCalledAsBareStatementsBeforeThen(): void
    {
        $pipeline = new Pipeline(new Container);

        $pipeline->send('foo');
        $pipeline->through([]);
        $pipeline->pipe(PipelineNoDiscardTestPipe::class);
        $pipeline->via('handle');

        $result = $pipeline->then(fn ($passable) => $passable.'|destination');

        $this->assertSame('foo|piped|destination', $result);
    }
}

class PipelineNoDiscardTestPipe
{
    public function handle($passable, $next)
    {
        return $next($passable.'|piped');
    }
}
