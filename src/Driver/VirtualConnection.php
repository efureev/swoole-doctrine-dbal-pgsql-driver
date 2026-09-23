<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Driver;

use Closure;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Override;
use PDO;
use PDOException;
use SwooleDoctrinePool\Coroutine\CoroutineApi;
use SwooleDoctrinePool\Exception\ConnectionLostException;
use SwooleDoctrinePool\Lease\Lease;
use SwooleDoctrinePool\Lease\LeaseBinder;
use SwooleDoctrinePool\Pool\ConnectionFactory;
use SwooleDoctrinePool\Pool\LostConnectionClassifier;
use SwooleDoctrinePool\Pool\Pool;
use SwooleDoctrinePool\Pool\PoolKey;

/**
 * Driver-level соединение, которое DBAL-обёртка держит всю свою жизнь. Само по себе ничего не
 * подключает: каждый вызов уходит в lease текущей корутины (берётся лениво), а вне корутины —
 * в одно прямое PDO-соединение без пула.
 */
final class VirtualConnection implements DriverConnection
{
    private ?Pool $pool = null;
    private ?Lease $directLease = null;
    private int $directLeaseId = 0;

    /** @param Closure(): Pool $poolResolver */
    public function __construct(
        private readonly PoolKey $key,
        private readonly Closure $poolResolver,
        private readonly ConnectionFactory $directFactory,
        private readonly LeaseBinder $binder,
        private readonly CoroutineApi $api,
        private readonly LostConnectionClassifier $classifier,
    ) {
    }

    public function __destruct()
    {
        $this->directLease?->release();
    }

    public function poolKey(): PoolKey
    {
        return $this->key;
    }

    /** Lease текущей корутины (или прямого режима) без взятия нового. */
    public function currentLease(): ?Lease
    {
        if (!$this->api->inCoroutine()) {
            $lease = $this->directLease;

            return $lease !== null && !$lease->isReleased() ? $lease : null;
        }

        return $this->binder->current($this->key->hash);
    }

    #[Override]
    public function prepare(string $sql): Statement
    {
        $lease = $this->lease();
        $statement = $this->guarded($lease, static fn(): Statement => $lease->connection()->prepare($sql));

        return new LeaseAwareStatement($statement, $lease, $this->api, $this->classifier);
    }

    #[Override]
    public function query(string $sql): Result
    {
        $lease = $this->lease();
        $result = $this->guarded($lease, static fn(): Result => $lease->connection()->query($sql));

        return new LeaseAwareResult($result, $lease);
    }

    #[Override]
    public function quote(string $value): string
    {
        $lease = $this->lease();

        return $this->guarded($lease, static fn(): string => $lease->connection()->quote($value));
    }

    #[Override]
    public function exec(string $sql): int|string
    {
        $lease = $this->lease();

        return $this->guarded($lease, static fn(): int|string => $lease->connection()->exec($sql));
    }

    #[Override]
    public function lastInsertId(): int|string
    {
        $lease = $this->lease();

        return $this->guarded($lease, static fn(): int|string => $lease->connection()->lastInsertId());
    }

    #[Override]
    public function beginTransaction(): void
    {
        $lease = $this->lease();
        $this->guarded($lease, static function () use ($lease): void {
            $lease->connection()->beginTransaction();
        });
    }

    #[Override]
    public function commit(): void
    {
        $lease = $this->lease();
        $this->guarded($lease, static function () use ($lease): void {
            $lease->connection()->commit();
        });
    }

    #[Override]
    public function rollBack(): void
    {
        $lease = $this->lease();
        $this->guarded($lease, static function () use ($lease): void {
            $lease->connection()->rollBack();
        });
    }

    #[Override]
    public function getNativeConnection(): PDO
    {
        return $this->lease()->pdo();
    }

    #[Override]
    public function getServerVersion(): string
    {
        return $this->pool?->serverVersion() ?? $this->lease()->connection()->getServerVersion();
    }

    /** @throws DriverException */
    private function lease(): Lease
    {
        if (!$this->api->inCoroutine()) {
            return $this->directLease();
        }

        $lease = $this->binder->current($this->key->hash);

        if ($lease !== null) {
            if (!$lease->isBroken()) {
                return $lease;
            }

            // Сюда попадаем, только если ошибку потери соединения поймали мимо DBAL (например, на голом PDO).
            $level = $lease->transaction->level;
            $lease->release();

            if ($level > 0) {
                throw ConnectionLostException::brokenInTransaction($this->key->label, $level);
            }
        }

        // Закрытый пул (console.terminate, worker_stop) не воскресает: реестр отдаст новый.
        if ($this->pool === null || $this->pool->isClosed()) {
            $this->pool = ($this->poolResolver)();
        }

        return $this->binder->acquire($this->pool);
    }

    /** @throws DriverException */
    private function directLease(): Lease
    {
        $lease = $this->directLease;

        if ($lease !== null && !$lease->isReleased() && !$lease->isBroken()) {
            return $lease;
        }

        $lease?->release();

        return $this->directLease = new Lease(
            id: ++$this->directLeaseId,
            ownerCid: -1,
            poolLabel: $this->key->label,
            physical: $this->directFactory->open(0),
            pool: null,
        );
    }

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    private function guarded(Lease $lease, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (DriverException | PDOException $e) {
            if ($this->classifier->isLost($e)) {
                $lease->markBroken();
            }

            throw $e;
        }
    }
}
