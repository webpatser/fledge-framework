<?php

namespace Illuminate\Tests\Integration\Concurrency;

use Illuminate\Concurrency\FiberDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;

class FiberDriverTestingTransactionsTest extends TestCase
{
    use DatabaseTransactions;

    protected string $databasePath;

    protected $connectionsToTransact = ['scoped'];

    protected function defineEnvironment($app)
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'fledge-testing-').'.sqlite';
        touch($this->databasePath);

        $app['config']->set('database.default', 'scoped');
        $app['config']->set('database.connections.scoped', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('create table items (name text)');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink($this->databasePath);
    }

    public function test_tasks_use_the_test_connection_and_transaction_under_the_testing_traits()
    {
        DB::table('items')->insert(['name' => 'factory row']);

        [[$connection, $count, $level]] = (new FiberDriver)->run([
            fn () => [DB::connection(), DB::table('items')->count(), DB::transactionLevel()],
        ]);

        $this->assertSame(DB::connection(), $connection);
        $this->assertSame(1, $count, 'The task cannot see rows written inside the test transaction.');
        $this->assertSame(1, $level);
    }
}
