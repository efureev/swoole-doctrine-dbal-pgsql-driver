<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use LogicException;
use PDO;
use SwooleDoctrinePool\Config\PoolConfig;

use function sprintf;

/**
 * Физическое PDO-соединение с метаданными. Ровно в одном из состояний: в idle-стеке пула,
 * принадлежит одному Lease, закрыто. Учёт не зависит от GC: закрытие — явный close().
 */
final class PhysicalConnection
{
    public float $lastReleasedAt;
    public int $uses = 0;
    public bool $broken = false;

    public function __construct(
        public readonly int $id,
        private ?PDO $pdo,
        private ?DriverConnection $driverConnection,
        public readonly string $serverVersion,
        public readonly float $createdAt,
    ) {
        $this->lastReleasedAt = $createdAt;
    }

    public function pdo(): PDO
    {
        return $this->pdo ?? throw new LogicException(sprintf('Соединение #%d уже закрыто.', $this->id));
    }

    public function driverConnection(): DriverConnection
    {
        return $this->driverConnection
            ?? throw new LogicException(sprintf('Соединение #%d уже закрыто.', $this->id));
    }

    public function isClosed(): bool
    {
        return $this->pdo === null;
    }

    /**
     * У PDO нет close(): сокет закрывается с последней ссылкой. Пул свои ссылки отпускает здесь;
     * Statement, который приложение удержало дольше запроса, продлит жизнь сокета — но не место в пуле.
     */
    public function close(): void
    {
        $this->pdo = null;
        $this->driverConnection = null;
    }

    public function idleFor(float $now): float
    {
        return $now - $this->lastReleasedAt;
    }

    public function age(float $now): float
    {
        return $now - $this->createdAt;
    }

    public function expiredReason(PoolConfig $config, float $now): ?CloseReason
    {
        if ($config->maxLifetime !== null && $this->age($now) >= $config->maxLifetime) {
            return CloseReason::MaxLifetime;
        }

        if ($config->maxUses > 0 && $this->uses >= $config->maxUses) {
            return CloseReason::MaxUses;
        }

        return null;
    }
}
