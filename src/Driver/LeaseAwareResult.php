<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Driver;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Doctrine\DBAL\Driver\Result;
use Override;
use SwooleDoctrinePool\Exception\LeaseViolationException;
use SwooleDoctrinePool\Lease\Lease;

/**
 * Результат, знающий свой lease. Чтение после release запрещено; чтение из другой корутины — нет:
 * pdo_pgsql буферизует результат на клиенте, к сокету оно уже не обращается.
 */
final class LeaseAwareResult extends AbstractResultMiddleware
{
    public function __construct(Result $result, private readonly Lease $lease)
    {
        parent::__construct($result);
    }

    #[Override]
    public function fetchNumeric(): array|false
    {
        $this->assertNotReleased('Result::fetchNumeric()');

        return parent::fetchNumeric();
    }

    #[Override]
    public function fetchAssociative(): array|false
    {
        $this->assertNotReleased('Result::fetchAssociative()');

        return parent::fetchAssociative();
    }

    #[Override]
    public function fetchOne(): mixed
    {
        $this->assertNotReleased('Result::fetchOne()');

        return parent::fetchOne();
    }

    #[Override]
    public function fetchAllNumeric(): array
    {
        $this->assertNotReleased('Result::fetchAllNumeric()');

        return parent::fetchAllNumeric();
    }

    #[Override]
    public function fetchAllAssociative(): array
    {
        $this->assertNotReleased('Result::fetchAllAssociative()');

        return parent::fetchAllAssociative();
    }

    #[Override]
    public function fetchFirstColumn(): array
    {
        $this->assertNotReleased('Result::fetchFirstColumn()');

        return parent::fetchFirstColumn();
    }

    #[Override]
    public function rowCount(): int|string
    {
        $this->assertNotReleased('Result::rowCount()');

        return parent::rowCount();
    }

    #[Override]
    public function columnCount(): int
    {
        $this->assertNotReleased('Result::columnCount()');

        return parent::columnCount();
    }

    #[Override]
    public function getColumnName(int $index): string
    {
        $this->assertNotReleased('Result::getColumnName()');

        return parent::getColumnName($index);
    }

    private function assertNotReleased(string $operation): void
    {
        if ($this->lease->isReleased()) {
            throw LeaseViolationException::released($this->lease->id, $this->lease->poolLabel, $operation);
        }
    }
}
