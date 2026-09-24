<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Fake;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Override;

final class FakeStatement implements Statement
{
    /** @var array<int|string, mixed> */
    public array $bound = [];

    public function __construct(
        private readonly FakeDriverConnection $connection,
        public readonly string $sql,
    ) {
    }

    #[Override]
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->bound[$param] = $value;
    }

    #[Override]
    public function execute(): Result
    {
        $this->connection->run('query', $this->sql);

        return new FakeResult($this->connection->rows);
    }
}
