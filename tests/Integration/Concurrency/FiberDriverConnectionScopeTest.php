<?php

namespace Illuminate\Tests\Integration\Concurrency;

use Carbon\CarbonInterval;
use Fledge\Async\CancelledException;
use Fledge\Async\DeferredFuture;
use Fledge\Concurrency\TaskConnectionScope;
use Illuminate\Bus\Queueable;
use Illuminate\Concurrency\FiberDriver;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Http\ConcurrentMiddleware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Routing\ConcurrentMiddlewareGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use WeakReference;

use function Fledge\Async\delay;

class FiberDriverConnectionScopeTest extends TestCase
{
    protected string $databasePath;

    protected function defineEnvironment($app)
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'fledge-scope-').'.sqlite';
        touch($this->databasePath);

        $app['config']->set('database.default', 'scoped');
        $app['config']->set('database.connections.scoped', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.connections.memory', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        ScopeProbe::$log = [];
        ScopeProbe::$connections = [];

        DB::statement('create table items (name text)');
    }

    protected function tearDown(): void
    {
        DB::purge('scoped');

        parent::tearDown();

        @unlink($this->databasePath);
    }

    public function test_a_transaction_opened_in_one_task_is_not_open_in_a_sibling()
    {
        $results = (new FiberDriver)->run([
            'writer' => function () {
                return DB::transaction(function () {
                    DB::table('items')->insert(['name' => 'uncommitted']);

                    delay(0.05);

                    return DB::transactionLevel();
                });
            },
            'reader' => function () {
                delay(0.01);

                return [DB::transactionLevel(), DB::table('items')->count()];
            },
        ]);

        $this->assertSame(1, $results['writer']);
        $this->assertSame([0, 0], $results['reader']);
        $this->assertSame(1, DB::table('items')->count());
    }

    public function test_each_task_gets_its_own_connection_and_releases_it()
    {
        $parent = DB::connection();
        $before = DB::getConnections();

        $task = function () {
            $connection = DB::connection();
            $connection->select('select 1');

            return [$connection, WeakReference::create($connection->getRawPdo())];
        };

        [[$first, $firstPdo], [$second, $secondPdo]] = (new FiberDriver)->run([$task, $task]);

        $this->assertNotSame($parent, $first);
        $this->assertNotSame($parent, $second);
        $this->assertNotSame($first, $second);
        $this->assertSame('scoped', $first->getName());

        // Each task's own PDO is gone; the kept instances now carry the shared connection's state.
        $this->assertNull($firstPdo->get(), 'A task PDO stays open after the run.');
        $this->assertNull($secondPdo->get(), 'A task PDO stays open after the run.');
        $this->assertSame($parent->getRawPdo(), $first->getRawPdo());

        $this->assertSame($parent, DB::connection());
        $this->assertSame($before, DB::getConnections());
        $this->assertNotNull($parent->getRawPdo());
    }

    public function test_a_task_sees_the_same_connection_for_its_whole_life()
    {
        [$same] = (new FiberDriver)->run([
            function () {
                $first = DB::connection();
                delay(0.01);

                return $first === DB::connection('scoped');
            },
        ]);

        $this->assertTrue($same);
    }

    public function test_a_failing_task_releases_its_connection()
    {
        $captured = $pdo = null;

        try {
            (new FiberDriver)->run([
                function () use (&$captured, &$pdo) {
                    $captured = DB::connection();
                    $captured->select('select 1');
                    $pdo = WeakReference::create($captured->getRawPdo());

                    throw new RuntimeException('task failed');
                },
            ]);

            $this->fail('The task exception was not rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('task failed', $e->getMessage());
        }

        $this->assertInstanceOf(Connection::class, $captured);
        $this->assertNotSame(DB::connection(), $captured);
        $this->assertNull($pdo->get());
    }

    public function test_a_timed_out_task_releases_its_connection_when_it_ends()
    {
        $captured = $pdo = null;
        $gate = new DeferredFuture;

        // The timeout timer is unreferenced; a referenced watcher keeps the loop alive while the task waits.
        $keepAlive = EventLoop::repeat(0.005, static fn () => null);

        try {
            (new FiberDriver)->run([
                function () use (&$captured, &$pdo, $gate) {
                    $captured = DB::connection();
                    $captured->select('select 1');
                    $pdo = WeakReference::create($captured->getRawPdo());

                    // Held open until the test releases it, long after the timeout fired.
                    $gate->getFuture()->await();
                },
            ], CarbonInterval::milliseconds(20));

            $this->fail('The timeout did not fire.');
        } catch (CancelledException) {
            //
        }

        $this->assertNotNull($pdo->get(), 'The abandoned task is still running.');

        // Completing the gate queues the task's resumption; a callback queued after it runs
        // once the task has returned and its scope's finally has run.
        $settled = new DeferredFuture;
        $gate->complete();
        EventLoop::queue(fn () => $settled->complete());
        $settled->getFuture()->await();
        EventLoop::cancel($keepAlive);

        $this->assertNull($pdo->get());
        $this->assertSame(0, TaskConnectionScope::$active);
    }

    public function test_after_commit_callbacks_follow_the_task_transaction()
    {
        $log = [];

        (new FiberDriver)->run([
            function () use (&$log) {
                DB::transaction(function () use (&$log) {
                    DB::afterCommit(function () use (&$log) {
                        $log[] = 'writer committed';
                    });

                    delay(0.05);
                });
            },
            function () use (&$log) {
                delay(0.01);

                DB::afterCommit(function () use (&$log) {
                    $log[] = 'sibling immediate';
                });
            },
        ]);

        $this->assertSame(['sibling immediate', 'writer committed'], $log);
    }

    public function test_parent_query_listeners_fire_for_task_queries()
    {
        $queries = [];

        DB::listen(function (QueryExecuted $event) use (&$queries) {
            $queries[] = $event->sql;
        });

        (new FiberDriver)->run([fn () => DB::select('select 42')]);

        $this->assertContains('select 42', $queries);
    }

    public function test_task_connections_dispatch_connection_established()
    {
        DB::connection();

        Event::fake(\Illuminate\Database\Events\ConnectionEstablished::class);

        (new FiberDriver)->run([fn () => DB::connection(), fn () => DB::connection()]);

        Event::assertDispatchedTimes(\Illuminate\Database\Events\ConnectionEstablished::class, 2);
    }

    public function test_in_memory_sqlite_stays_shared_because_a_new_connection_would_be_another_database()
    {
        $parent = DB::connection('memory');

        [$connection] = (new FiberDriver)->run([fn () => DB::connection('memory')]);

        $this->assertSame($parent, $connection);
    }

    public function test_an_after_commit_job_waits_for_its_own_task_commit_not_a_siblings()
    {
        (new FiberDriver)->run([
            'writer' => function () {
                DB::transaction(function () {
                    AfterCommitProbeJob::dispatch('writer')->afterCommit();

                    delay(0.05);

                    ScopeProbe::$log[] = 'writer before commit';
                });
            },
            'sibling' => function () {
                delay(0.01);

                DB::transaction(fn () => DB::table('items')->insert(['name' => 'sibling']));

                ScopeProbe::$log[] = 'sibling committed';
            },
        ]);

        $this->assertSame(['sibling committed', 'writer before commit', 'job writer'], ScopeProbe::$log);
    }

    public function test_an_after_commit_job_is_not_queued_when_the_task_rolls_back()
    {
        (new FiberDriver)->run([
            function () {
                try {
                    DB::transaction(function () {
                        AfterCommitProbeJob::dispatch('rolled back')->afterCommit();

                        delay(0.01);

                        throw new RuntimeException('roll back');
                    });
                } catch (RuntimeException) {
                    ScopeProbe::$log[] = 'rolled back';
                }
            },
            function () {
                DB::transaction(fn () => DB::table('items')->insert(['name' => 'sibling']));
            },
        ]);

        $this->assertSame(['rolled back'], ScopeProbe::$log);
    }

    public function test_after_commit_listeners_follow_the_task_transaction()
    {
        Event::listen(ScopeProbeEvent::class, AfterCommitProbeListener::class);

        (new FiberDriver)->run([
            'committer' => function () {
                DB::transaction(function () {
                    Event::dispatch(new ScopeProbeEvent('committer'));

                    delay(0.05);

                    ScopeProbe::$log[] = 'committer before commit';
                });
            },
            'roller' => function () {
                try {
                    DB::transaction(function () {
                        Event::dispatch(new ScopeProbeEvent('roller'));

                        throw new RuntimeException('roll back');
                    });
                } catch (RuntimeException) {
                    ScopeProbe::$log[] = 'roller rolled back';
                }
            },
        ]);

        $this->assertSame(['roller rolled back', 'committer before commit', 'listener committer'], ScopeProbe::$log);
    }

    public function test_after_commit_jobs_outside_a_task_are_unchanged()
    {
        DB::transaction(function () {
            AfterCommitProbeJob::dispatch('parent')->afterCommit();

            ScopeProbe::$log[] = 'parent before commit';
        });

        $this->assertSame(['parent before commit', 'job parent'], ScopeProbe::$log);
    }

    public function test_a_task_without_its_own_transaction_runs_after_commit_work_at_once()
    {
        try {
            DB::transaction(function () {
                (new FiberDriver)->run([function () {
                    DB::table('items')->insert(['name' => 'task']);

                    AfterCommitProbeJob::dispatch('task')->afterCommit();
                }]);

                throw new RuntimeException('parent rolls back');
            });
        } catch (RuntimeException) {
            //
        }

        $this->assertSame(['job task'], ScopeProbe::$log);
        $this->assertSame(1, DB::table('items')->count());
    }

    public function test_a_nested_task_does_not_attach_to_the_parent_transaction()
    {
        try {
            DB::transaction(function () {
                (new FiberDriver)->run([function () {
                    DB::transaction(function () {
                        (new FiberDriver)->run([function () {
                            AfterCommitProbeJob::dispatch('inner')->afterCommit();
                        }]);

                        ScopeProbe::$log[] = 'outer before commit';
                    });
                }]);

                throw new RuntimeException('parent rolls back');
            });
        } catch (RuntimeException) {
            //
        }

        $this->assertSame(['job inner', 'outer before commit'], ScopeProbe::$log);
    }

    public function test_a_task_on_a_shared_connection_attaches_to_the_parent_transaction_there()
    {
        DB::connection('memory')->transaction(function () {
            (new FiberDriver)->run([function () {
                DB::connection('memory')->select('select 1');

                AfterCommitProbeJob::dispatch('shared')->afterCommit();

                ScopeProbe::$log[] = 'task done';
            }]);

            ScopeProbe::$log[] = 'parent before commit';
        });

        $this->assertSame(['task done', 'parent before commit', 'job shared'], ScopeProbe::$log);
    }

    public function test_a_database_queue_first_resolved_in_a_task_uses_the_shared_connection_afterwards()
    {
        DB::statement('create table jobs (id integer primary key autoincrement, queue text, payload text, attempts integer, reserved_at integer null, available_at integer, created_at integer)');
        $this->app['config']->set('queue.connections.fledge-db', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);

        [$queue] = (new FiberDriver)->run([fn () => $this->app['queue']->connection('fledge-db')]);

        DB::beginTransaction();
        $queue->push(new AfterCommitProbeJob('queued'));
        $this->assertSame(1, DB::table('jobs')->count());
        DB::rollBack();

        $this->assertSame(0, DB::table('jobs')->count(), 'The queue wrote outside the parent transaction.');
        $this->assertSame(DB::connection()->getRawPdo(), $queue->getDatabase()->getRawPdo());

        DB::transaction(function () use ($queue) {
            $queue->push((new AfterCommitProbeJob('after commit'))->afterCommit());

            $this->assertSame(1, $queue->getDatabase()->transactionLevel());
            $this->assertSame(0, DB::table('jobs')->count());
        });

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_database_cache_store_first_resolved_in_a_task_uses_the_shared_connection_afterwards()
    {
        DB::statement('create table cache_entries (key text primary key, value text, expiration integer)');
        $this->app['config']->set('cache.stores.fledge-db', ['driver' => 'database', 'table' => 'cache_entries']);

        [$store] = (new FiberDriver)->run([fn () => Cache::store('fledge-db')]);

        DB::beginTransaction();
        $store->put('key', 'value', 60);
        DB::rollBack();

        $this->assertNull($store->get('key'), 'The cache store wrote outside the parent transaction.');

        $store->put('key', 'value', 60);
        $this->assertSame('value', $store->get('key'));
    }

    public function test_concurrent_middleware_members_use_the_request_connection()
    {
        $this->app['router']->concurrentMiddleware('scope-probe', [ConnectionProbeAlpha::class, ConnectionProbeBeta::class]);
        $this->app['router']->aliasMiddleware('concurrent', ConcurrentMiddlewareGroup::class);
        $this->app['router']->get('/scope-probe', fn () => 'ok')->middleware('concurrent:scope-probe');

        $parent = DB::connection();

        DB::beginTransaction();
        DB::table('items')->insert(['name' => 'request write']);

        try {
            $this->get('/scope-probe')->assertOk();
        } finally {
            DB::rollBack();
        }

        $this->assertCount(2, ScopeProbe::$connections);

        foreach (ScopeProbe::$connections as [$connection, $count]) {
            $this->assertSame($parent, $connection);
            $this->assertSame(1, $count);
        }

        [$task] = (new FiberDriver)->run([fn () => DB::connection()]);

        $this->assertNotSame($parent, $task);
    }
}

class ScopeProbe
{
    public static array $log = [];

    public static array $connections = [];
}

class AfterCommitProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $name)
    {
        //
    }

    public function handle(): void
    {
        ScopeProbe::$log[] = 'job '.$this->name;
    }
}

class ScopeProbeEvent
{
    public function __construct(public string $name)
    {
        //
    }
}

class AfterCommitProbeListener implements ShouldHandleEventsAfterCommit
{
    public function handle(ScopeProbeEvent $event): void
    {
        ScopeProbe::$log[] = 'listener '.$event->name;
    }
}

class ConnectionProbeAlpha implements ConcurrentMiddleware
{
    public function before(Request $request): Request|Response
    {
        ScopeProbe::$connections[] = [DB::connection(), DB::table('items')->count()];

        return $request;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response;
    }
}

class ConnectionProbeBeta extends ConnectionProbeAlpha
{
    //
}
