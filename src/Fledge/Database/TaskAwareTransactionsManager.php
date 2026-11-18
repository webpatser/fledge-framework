<?php

namespace Fledge\Database;

use Fledge\Concurrency\TaskConnectionScope;
use Fledge\Fiber\Database\Native\FiberAwareTransactionsManager;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Collection;

/**
 * The application's `db.transactions` manager, aware of fiber driver tasks.
 *
 * A Concurrency::run() task on the fiber driver runs its transactions on
 * connections of its own, tracked by a transactions manager of its own (see
 * TaskConnectionScope). Code that registers after-commit work through the
 * container binding (queued jobs with afterCommit(), ShouldHandleEventsAfterCommit
 * listeners, ShouldDispatchAfterCommit events, broadcasts) reaches this
 * manager. Inside a task the connection-less calls follow the process driver,
 * where a task is a child process with its own connections:
 *
 * - The task has a transaction open on its own connections: the callback
 *   follows that transaction (the task's own manager).
 * - Otherwise its work on its own connections is already committed, so the
 *   callback runs at once; it never waits for a transaction of the parent or
 *   of an enclosing task, which run on other connections. The exception is a
 *   connection the task shares with the enclosing context (in-memory SQLite,
 *   a native pooled connection): a transaction open there covers the task's
 *   work, so the callback attaches to it as it would outside a task.
 *
 * Outside a task every call goes to the parent class unchanged.
 *
 * The parent is fledge-fiber's FiberAwareTransactionsManager, which keeps the
 * records of the native pooled drivers per fiber. Extending it, rather than
 * wrapping it, keeps the two from fighting: fledge-fiber's provider only wraps
 * the exact stock class, so it leaves this binding alone, and a native
 * connection's FiberAwareTransactionsManager::wrap() returns this instance
 * itself, so its per-fiber registration lands on the application's manager.
 */
class TaskAwareTransactionsManager extends FiberAwareTransactionsManager
{
    public function addCallback($callback)
    {
        $applicable = $this->taskTransactions();

        if ($applicable === null) {
            parent::addCallback($callback);

            return;
        }

        if ($current = $applicable->last()) {
            $current->addCallback($callback);

            return;
        }

        $callback();
    }

    public function addCallbackForRollback($callback)
    {
        $applicable = $this->taskTransactions();

        if ($applicable === null) {
            parent::addCallbackForRollback($callback);

            return;
        }

        $applicable->last()?->addCallbackForRollback($callback);
    }

    public function callbackApplicableTransactions()
    {
        return $this->taskTransactions() ?? parent::callbackApplicableTransactions();
    }

    public function afterCommitCallbacksShouldBeExecuted($level)
    {
        return TaskConnectionScope::pendingTransactionsManager()?->afterCommitCallbacksShouldBeExecuted($level)
            ?? parent::afterCommitCallbacksShouldBeExecuted($level);
    }

    public function getPendingTransactions()
    {
        return TaskConnectionScope::pendingTransactionsManager()?->getPendingTransactions()
            ?? parent::getPendingTransactions();
    }

    public function getCommittedTransactions()
    {
        return TaskConnectionScope::pendingTransactionsManager()?->getCommittedTransactions()
            ?? parent::getCommittedTransactions();
    }

    /**
     * The transactions a callback registered by the current task may wait for; null outside a task.
     *
     * @return Collection<int, DatabaseTransactionRecord>|null
     */
    private function taskTransactions(): ?Collection
    {
        $scope = TaskConnectionScope::current();

        if ($scope === null) {
            return null;
        }

        if ($own = TaskConnectionScope::pendingTransactionsManager()) {
            return $own->callbackApplicableTransactions();
        }

        return parent::callbackApplicableTransactions()
            ->filter(fn (DatabaseTransactionRecord $transaction) => $scope->sharesConnection($transaction->connection))
            ->values();
    }
}
