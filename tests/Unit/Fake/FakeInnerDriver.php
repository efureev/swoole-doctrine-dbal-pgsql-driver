<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Fake;

use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Override;
use PDOException;

/**
 * Внутренний «pdo_pgsql»-драйвер для тестов полного стека: Driver → VirtualConnection → Pool → PDO.
 */
final class FakeInnerDriver extends AbstractPostgreSQLDriver
{
    /** @var list<FakeDriverConnection> */
    public array $connections = [];
    /** @var list<array<string, mixed>> */
    public array $paramsSeen = [];
    public ?PDOException $failWith = null;

    #[Override]
    public function connect(array $params): Connection
    {
        $this->paramsSeen[] = $params;

        if ($this->failWith !== null) {
            throw Exception::new($this->failWith);
        }

        return $this->connections[] = new FakeDriverConnection(new FakePdo());
    }

    public function lastPdo(): FakePdo
    {
        return $this->connections[array_key_last($this->connections)]->pdo;
    }
}
