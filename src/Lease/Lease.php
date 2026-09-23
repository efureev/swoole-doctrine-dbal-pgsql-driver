<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Lease;

use Closure;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use PDO;
use SwooleDoctrinePool\Exception\LeaseViolationException;
use SwooleDoctrinePool\Pool\PhysicalConnection;
use SwooleDoctrinePool\Pool\Pool;
use Throwable;

/**
 * Право одной корутины на одно физическое соединение. Владелец фиксируется при выдаче; release()
 * идемпотентен, потому что его зовут и defer, и terminate-слушатель, и close() обёртки.
 */
final class Lease
{
    private bool $released = false;
    private bool $broken = false;

    /**
     * @param ?Pool    $pool      null — прямой режим: соединение открыто вне пула и закрывается с lease
     * @param ?Closure $onRelease снимает lease из LeaseSet корутины
     */
    public function __construct(
        public readonly int $id,
        public readonly int $ownerCid,
        public readonly string $poolLabel,
        private readonly PhysicalConnection $physical,
        private readonly ?Pool $pool,
        public readonly TransactionState $transaction = new TransactionState(),
        private readonly ?Closure $onRelease = null,
    ) {
    }

    public function connection(): DriverConnection
    {
        return $this->physical->driverConnection();
    }

    public function pdo(): PDO
    {
        return $this->physical->pdo();
    }

    public function connectionId(): int
    {
        return $this->physical->id;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function isBroken(): bool
    {
        return $this->broken;
    }

    public function markBroken(): void
    {
        $this->broken = true;
        $this->physical->broken = true;
    }

    /** @throws LeaseViolationException */
    public function assertUsable(int $cid, string $operation): void
    {
        if ($this->released) {
            throw LeaseViolationException::released($this->id, $this->poolLabel, $operation);
        }

        if ($cid !== $this->ownerCid) {
            throw LeaseViolationException::foreignCoroutine(
                $this->id,
                $this->poolLabel,
                $this->ownerCid,
                $cid,
                $operation,
            );
        }
    }

    public function release(bool $viaDefer = false): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if ($this->pool !== null) {
            $this->pool->release($this->physical, $this->broken, $viaDefer);
        } else {
            $this->closeDirect();
        }

        if ($this->onRelease !== null) {
            ($this->onRelease)($this);
        }
    }

    private function closeDirect(): void
    {
        if (!$this->broken && !$this->physical->isClosed()) {
            try {
                if ($this->physical->pdo()->inTransaction()) {
                    $this->physical->pdo()->rollBack();
                }
            } catch (Throwable) {
                // Соединение всё равно закрывается; сервер откатит транзакцию сам.
            }
        }

        $this->physical->close();
    }
}
