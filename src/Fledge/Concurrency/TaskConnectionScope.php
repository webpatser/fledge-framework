<?php

namespace Fledge\Concurrency;

use Closure;
use Fiber;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseTransactionsManager;
use WeakMap;

/**
 * Per-task database connections for the fiber concurrency driver.
 *
 * FiberDriver runs every task through run(). While a task runs,
 * DatabaseManager::connection() asks connection() first: inside a task it
 * returns a connection that belongs to that task alone (made from the same
 * configuration, opened lazily on the first query), so transaction state, the
 * query log and the PDO session never leak between concurrent tasks. Outside
 * a task the manager only reads the $active counter and carries on as stock.
 *
 * - One connection per task per connection name (including ::read / ::write),
 *   closed (not pooled) when the task ends: on return, on exception, and when
 *   an abandoned task's fiber is destroyed (PHP unwinds it through finally).
 * - The task's connections share one transactions manager of their own, so
 *   afterCommit callbacks follow the task's transaction. The event dispatcher
 *   is the application's, so DB::listen() listeners fire for task queries and
 *   ConnectionEstablished is dispatched for each task connection.
 * - Delegated to the shared connection: drivers that already lease per fiber
 *   (the fledge-fiber native pool) and in-memory SQLite, where a new
 *   connection would be a different, empty database.
 * - Only the task's own fiber is scoped: fibers a task starts itself (async())
 *   see the shared connections, and DB::purge(), disconnect() and reconnect()
 *   still address the shared connection.
 */
final class TaskConnectionScope
{
    /**
     * The number of task scopes alive right now; zero outside the fiber driver.
     */
    public static int $active = 0;

    /**
     * @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, self>|null
     */
    private static ?WeakMap $scopes = null;

    /**
     * @var array<class-string, bool>
     */
    private static array $selfScoping = [];

    /**
     * The task's connections; false marks a name delegated to the shared connection.
     *
     * @var array<string, Connection|false>
     */
    private array $connections = [];

    private ?DatabaseTransactionsManager $transactions = null;

    /**
     * The manager and connection name of each of the task's own database connections.
     *
     * @var array<string, array{DatabaseManager, string}>
     */
    private array $owners = [];

    /**
     * Connection names (without ::read / ::write) this task shares with the enclosing context.
     *
     * @var array<string, true>
     */
    private array $sharedNames = [];

    /**
     * Set while a released task connection resolves the shared connection it hands over to.
     */
    private static bool $bypass = false;

    /**
     * Run a task with connections of its own.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $task
     * @return TReturn
     */
    public static function run(Closure $task): mixed
    {
        // The Fiber::getCurrent() stub returns the class without its template types.
        /** @var Fiber<mixed, mixed, mixed, mixed>|null $fiber */
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            return $task();
        }

        $scope = new self;

        self::$scopes ??= new WeakMap;
        self::$scopes[$fiber] = $scope;
        self::$active++;

        try {
            return $task();
        } finally {
            // The event loop reuses callback fibers, so the entry must go now, not with the fiber.
            unset(self::$scopes[$fiber]);
            self::$active--;

            $scope->release();
        }
    }

    /**
     * The current task's connection for the given name, or null when the caller
     * is not a task or the name is delegated to the shared connection.
     */
    public static function connection(DatabaseManager $manager, string $name): ?Connection
    {
        $fiber = Fiber::getCurrent();

        if (self::$bypass || $fiber === null || ($scope = self::$scopes[$fiber] ?? null) === null) {
            return null;
        }

        return $scope->resolve($manager, $name);
    }

    /**
     * The scope of the task running in the current fiber, if any.
     */
    public static function current(): ?self
    {
        if (self::$active === 0 || ($fiber = Fiber::getCurrent()) === null) {
            return null;
        }

        return self::$scopes[$fiber] ?? null;
    }

    /**
     * Whether this task uses the enclosing context's connection for the given name, so a
     * transaction open there also covers the task's work on it.
     */
    public function sharesConnection(?string $name): bool
    {
        return isset($this->sharedNames[(string) $name]);
    }

    /**
     * The current task's transactions manager while that task has a transaction open on one
     * of its own connections; null otherwise (and always outside a task).
     */
    public static function pendingTransactionsManager(): ?DatabaseTransactionsManager
    {
        if (self::$active === 0 || ($fiber = Fiber::getCurrent()) === null) {
            return null;
        }

        $manager = (self::$scopes[$fiber] ?? null)?->transactions;

        return $manager !== null && $manager->getPendingTransactions()->isNotEmpty() ? $manager : null;
    }

    private function resolve(DatabaseManager $manager, string $name): ?Connection
    {
        $key = spl_object_id($manager)."\0".$name;

        if (array_key_exists($key, $this->connections)) {
            return $this->connections[$key] ?: null;
        }

        $shared = $manager->getConnections()[$name] ?? null;

        if (($shared !== null && self::scopesItself($shared)) || self::isInMemory($manager, $name) || self::underTestTransactions($manager)) {
            return $this->share($key, $name);
        }

        $connection = self::make($manager, $name);

        if (self::scopesItself($connection)) {
            // The PDO is resolved lazily, so dropping this instance opened nothing.
            return $this->share($key, $name);
        }

        $this->owners[$key] = [$manager, $name];

        $connection->setTransactionManager($this->transactions ??= new DatabaseTransactionsManager);

        $connection->setReconnector(static function (Connection $connection) use ($manager, $name) {
            $fresh = self::make($manager, $name);

            $connection->setPdo($fresh->getRawPdo())
                ->setReadPdo($fresh->getRawReadPdo())
                ->setDirectPdo($fresh->getRawDirectPdo());
        });

        (fn () => $this->dispatchConnectionEstablishedEvent($connection))->call($manager);

        return $this->connections[$key] = $connection;
    }

    private function share(string $key, string $name): null
    {
        $this->connections[$key] = false;
        $this->sharedNames[explode('::', $name, 2)[0]] = true;

        return null;
    }

    /**
     * RefreshDatabase and DatabaseTransactions wrap each test in a transaction on the
     * test's connections and install the testing manager: tasks then use those
     * connections, as the sync driver would, so they see the test's rows and leave none.
     */
    private static function underTestTransactions(DatabaseManager $manager): bool
    {
        return (fn () => $this->app->bound('db.transactions') ? $this->app->make('db.transactions') : null)->call($manager)
            instanceof \Illuminate\Foundation\Testing\DatabaseTransactionsManager;
    }

    /**
     * A task connection that something kept (a queue, cache store or session handler first
     * resolved inside the task) must not live on as a private connection with the task's
     * transactions manager. After its release it takes over the shared connection's state
     * by reference (PDOs, transaction level, transactions manager, reconnector, query log),
     * so the holder behaves exactly as if it had resolved the shared connection.
     */
    private static function handOver(Connection $connection, DatabaseManager $manager, string $name): void
    {
        $shared = $manager->getConnections()[$name] ?? null;

        if ($shared !== null) {
            self::adopt($connection, $shared);

            return;
        }

        // No shared connection yet: make it only if the kept connection is used again.
        $connection->setReconnector(static function (Connection $connection) use ($manager, $name) {
            self::$bypass = true;

            try {
                $shared = $manager->connection($name);
            } finally {
                self::$bypass = false;
            }

            self::adopt($connection, $shared);
        });
    }

    private static function adopt(Connection $connection, Connection $shared): void
    {
        if ($connection === $shared || $connection::class !== $shared::class) {
            return;
        }

        (function () use ($shared) {
            foreach (array_keys(get_object_vars($shared)) as $property) {
                $this->{$property} = &$shared->{$property};
            }
        })->call($connection);
    }

    /**
     * Make and configure a connection exactly like DatabaseManager::connection() does.
     */
    private static function make(DatabaseManager $manager, string $name): Connection
    {
        return (function () use ($name) {
            [$database, $type] = $this->parseConnectionName($name);

            return $this->configure($this->makeConnection($database), $type);
        })->call($manager);
    }

    private static function isInMemory(DatabaseManager $manager, string $name): bool
    {
        $config = (fn () => $this->configuration($this->parseConnectionName($name)[0]))->call($manager);

        $database = (string) ($config['database'] ?? '');

        return ($config['driver'] ?? null) === 'sqlite'
            && ($database === ':memory:' || str_contains($database, 'mode=memory'));
    }

    /**
     * Whether the connection already keeps its PDO and transaction state per fiber.
     */
    private static function scopesItself(Connection $connection): bool
    {
        return self::$selfScoping[$connection::class] ??= in_array(
            'Fledge\Fiber\Database\Native\UsesNativePdoPool', class_uses_recursive($connection), true
        );
    }

    private function release(): void
    {
        $connections = $this->connections;
        $owners = $this->owners;
        $this->connections = $this->owners = [];

        foreach ($connections as $key => $connection) {
            if ($connection instanceof Connection) {
                $connection->disconnect();

                self::handOver($connection, ...$owners[$key]);
            }
        }
    }
}
