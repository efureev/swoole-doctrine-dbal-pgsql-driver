<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\DBAL;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Exception\CommitFailedRollbackOnly;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Doctrine\DBAL\TransactionIsolationLevel;
use Override;
use SensitiveParameter;
use SwooleDoctrinePool\Coroutine\CoroutineApi;
use SwooleDoctrinePool\Coroutine\SwooleCoroutineApi;
use SwooleDoctrinePool\Exception\LeaseViolationException;
use SwooleDoctrinePool\Lease\LeaseBinder;
use SwooleDoctrinePool\Lease\TransactionState;
use SwooleDoctrinePool\Pool\PoolKey;

/**
 * DBAL-обёртка, безопасная для конкурентных корутин. Базовая Doctrine\DBAL\Connection — синглтон
 * контейнера — хранит уровень вложенности транзакции в одном приватном поле для всех корутин воркера;
 * здесь состояние транзакции живёт на lease текущей корутины, то есть рядом с соединением,
 * на котором транзакция открыта.
 *
 * @psalm-suppress InternalMethod convertException() помечен @internal, но это штатный способ конвертации
 *                                для подкласса обёртки — сам DBAL зовёт его из тех же методов.
 */
class CoroutineSafeConnection extends Connection
{
    private readonly CoroutineApi $api;
    private readonly LeaseBinder $binder;
    private readonly PoolKey $poolKey;
    private readonly TransactionState $directTransaction;
    private bool $autoCommitMode;

    /** @param array<string, mixed> $params */
    public function __construct(
        #[SensitiveParameter]
        array $params,
        Driver $driver,
        ?Configuration $config = null,
        ?CoroutineApi $api = null,
    ) {
        parent::__construct($params, $driver, $config);

        $this->api = $api ?? SwooleCoroutineApi::create();
        $this->binder = new LeaseBinder($this->api);
        $this->poolKey = PoolKey::fromParams($params);
        $this->directTransaction = new TransactionState();
        $this->autoCommitMode = $this->_config->getAutoCommit();
    }

    public function getPoolKey(): PoolKey
    {
        return $this->poolKey;
    }

    /** Отдаёт соединение текущей корутины в пул. То же, что close(); имя точнее описывает происходящее. */
    public function release(): void
    {
        $this->close();
    }

    #[Override]
    protected function connect(): DriverConnection
    {
        if ($this->_conn === null) {
            try {
                $this->_conn = $this->driver->connect($this->getParams());
            } catch (Driver\Exception $e) {
                throw $this->convertException($e);
            }
        }

        if (!$this->autoCommitMode && ($this->txState()?->level ?? 0) === 0) {
            // Напрямую в драйвер, минуя beginTransaction(): та ведёт учёт вложенности поверх этого уровня.
            try {
                $this->_conn->beginTransaction();
            } catch (Driver\Exception $e) {
                throw $this->convertException($e);
            }

            $this->requireTxState()->level = 1;
        }

        return $this->_conn;
    }

    #[Override]
    public function isConnected(): bool
    {
        if (!$this->api->inCoroutine()) {
            return $this->_conn !== null;
        }

        return $this->binder->current($this->poolKey->hash) !== null;
    }

    /**
     * В корутине освобождает только её lease; driver-level соединение остаётся — оно не хранит
     * состояния. Вне корутины закрывает прямое соединение, незавершённая транзакция откатывается.
     */
    #[Override]
    public function close(): void
    {
        if ($this->api->inCoroutine()) {
            $this->binder->current($this->poolKey->hash)?->release();

            return;
        }

        $this->directTransaction->reset();
        $this->_conn = null;
    }

    #[Override]
    public function isAutoCommit(): bool
    {
        return $this->autoCommitMode;
    }

    #[Override]
    public function setAutoCommit(bool $autoCommit): void
    {
        if ($autoCommit === $this->autoCommitMode) {
            return;
        }

        $this->autoCommitMode = $autoCommit;

        if ($this->_conn === null) {
            return;
        }

        while (($state = $this->txState()) !== null && $state->level !== 0) {
            if (!$this->autoCommitMode && $state->level === 1) {
                // Последний commit без autocommit сразу откроет новую транзакцию — иначе цикл бесконечен.
                $this->commit();

                return;
            }

            $this->commit();
        }
    }

    #[Override]
    public function isTransactionActive(): bool
    {
        return ($this->txState()?->level ?? 0) > 0;
    }

    #[Override]
    public function getTransactionNestingLevel(): int
    {
        return $this->txState()?->level ?? 0;
    }

    #[Override]
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- имя задано Doctrine\DBAL\Connection
    protected function _getNestedTransactionSavePointName(): string
    {
        return 'DOCTRINE_' . ($this->txState()?->level ?? 0);
    }

    #[Override]
    public function beginTransaction(): void
    {
        $connection = $this->connect();
        $state = $this->txState();

        if ($state === null || $state->level === 0) {
            try {
                $connection->beginTransaction();
            } catch (Driver\Exception $e) {
                throw $this->convertException($e);
            }

            $this->requireTxState()->level = 1;

            return;
        }

        ++$state->level;
        $this->createSavepoint($this->_getNestedTransactionSavePointName());
    }

    #[Override]
    public function commit(): void
    {
        $state = $this->txState();

        if ($state === null || $state->level === 0) {
            throw NoActiveTransaction::new();
        }

        if ($state->rollbackOnly) {
            throw CommitFailedRollbackOnly::new();
        }

        $connection = $this->connect();

        try {
            if ($state->level === 1) {
                try {
                    $connection->commit();
                } catch (Driver\Exception $e) {
                    throw $this->convertException($e);
                }
            } else {
                $this->releaseSavepoint($this->_getNestedTransactionSavePointName());
            }
        } finally {
            // Lease мог уйти по ConnectionLost (DBAL зовёт close()): тогда состояние уже не наше.
            if ($this->txState() === $state) {
                if ($state->level !== 0) {
                    --$state->level;
                }

                if (!$this->autoCommitMode && $state->level === 0) {
                    $this->connect();
                }
            }
        }
    }

    #[Override]
    public function rollBack(): void
    {
        $state = $this->txState();

        if ($state === null || $state->level === 0) {
            throw NoActiveTransaction::new();
        }

        $connection = $this->connect();

        if ($state->level === 1) {
            $state->level = 0;

            try {
                $connection->rollBack();
            } catch (Driver\Exception $e) {
                throw $this->convertException($e);
            } finally {
                $state->rollbackOnly = false;

                if (!$this->autoCommitMode && $this->txState() === $state) {
                    $this->connect();
                }
            }

            return;
        }

        $this->rollbackSavepoint($this->_getNestedTransactionSavePointName());
        --$state->level;
    }

    #[Override]
    public function setRollbackOnly(): void
    {
        $state = $this->txState();

        if ($state === null || $state->level === 0) {
            throw NoActiveTransaction::new();
        }

        $state->rollbackOnly = true;
    }

    #[Override]
    public function isRollbackOnly(): bool
    {
        $state = $this->txState();

        if ($state === null || $state->level === 0) {
            throw NoActiveTransaction::new();
        }

        return $state->rollbackOnly;
    }

    /** Уровень изоляции — свойство сессии Postgres: живёт с lease и при reset_on_release=rollback_only утекает. */
    #[Override]
    public function setTransactionIsolation(TransactionIsolationLevel $level): void
    {
        $this->executeStatement($this->getDatabasePlatform()->getSetTransactionIsolationSQL($level));
        $this->requireTxState()->isolation = $level;
    }

    #[Override]
    public function getTransactionIsolation(): TransactionIsolationLevel
    {
        return $this->txState()?->isolation ?? $this->getDatabasePlatform()->getDefaultTransactionIsolationLevel();
    }

    private function txState(): ?TransactionState
    {
        if (!$this->api->inCoroutine()) {
            return $this->directTransaction;
        }

        return $this->binder->current($this->poolKey->hash)?->transaction;
    }

    private function requireTxState(): TransactionState
    {
        return $this->txState() ?? throw LeaseViolationException::leaseNotFound($this->poolKey->label);
    }
}
