<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Fake;

use Closure;
use Doctrine\DBAL\Driver\PDO\Exception;
use Override;
use PDOException;
use SwooleDoctrinePool\Pool\ConnectionFactory;
use SwooleDoctrinePool\Pool\PhysicalConnection;

final class FakeConnectionFactory implements ConnectionFactory
{
    /** @var list<PhysicalConnection> */
    public array $opened = [];
    /** @var list<FakePdo> */
    public array $pdos = [];
    public ?PDOException $failWith = null;
    /** @var ?Closure(int): void вызывается во время open() — имитация переключения корутин на коннекте */
    public ?Closure $onOpen = null;

    public function __construct(private readonly FakeClock $clock)
    {
    }

    #[Override]
    public function open(int $id): PhysicalConnection
    {
        if ($this->onOpen !== null) {
            ($this->onOpen)($id);
        }

        if ($this->failWith !== null) {
            throw Exception::new($this->failWith);
        }

        $pdo = new FakePdo();
        $this->pdos[] = $pdo;

        $connection = new PhysicalConnection(
            $id,
            $pdo,
            new FakeDriverConnection($pdo),
            $pdo->serverVersion,
            $this->clock->now(),
        );
        $this->opened[] = $connection;

        return $connection;
    }

    public function lastPdo(): FakePdo
    {
        return $this->pdos[array_key_last($this->pdos)];
    }
}
