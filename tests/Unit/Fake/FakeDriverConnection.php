<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Fake;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Override;
use PDO;
use PDOException;

/**
 * Driver-level соединение поверх FakePdo: SQL пишется в лог PDO, PDOException превращается
 * в Doctrine\DBAL\Driver\PDO\Exception — как в настоящем Driver\PDO\Connection.
 */
final class FakeDriverConnection implements Connection
{
    /** @var list<list<mixed>> */
    public array $rows = [[1]];

    public function __construct(public readonly FakePdo $pdo)
    {
    }

    #[Override]
    public function prepare(string $sql): Statement
    {
        return new FakeStatement($this, $sql);
    }

    #[Override]
    public function query(string $sql): Result
    {
        $this->run('query', $sql);

        return new FakeResult($this->rows);
    }

    #[Override]
    public function quote(string $value): string
    {
        return "'" . $value . "'";
    }

    #[Override]
    public function exec(string $sql): int|string
    {
        $this->run('exec', $sql);

        return 1;
    }

    #[Override]
    public function lastInsertId(): int|string
    {
        return $this->wrap(fn(): string|false => $this->pdo->lastInsertId()) ?: '0';
    }

    #[Override]
    public function beginTransaction(): void
    {
        $this->wrap(fn(): bool => $this->pdo->beginTransaction());
    }

    #[Override]
    public function commit(): void
    {
        $this->wrap(fn(): bool => $this->pdo->commit());
    }

    #[Override]
    public function rollBack(): void
    {
        $this->wrap(fn(): bool => $this->pdo->rollBack());
    }

    #[Override]
    public function getNativeConnection(): PDO
    {
        return $this->pdo;
    }

    #[Override]
    public function getServerVersion(): string
    {
        return $this->pdo->serverVersion;
    }

    /** @internal для FakeStatement */
    public function run(string $method, string $sql): void
    {
        $this->wrap(function () use ($method, $sql): void {
            if ($method === 'exec') {
                $this->pdo->exec($sql);

                return;
            }

            $this->pdo->query($sql);
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function wrap(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (PDOException $e) {
            throw Exception::new($e);
        }
    }
}
