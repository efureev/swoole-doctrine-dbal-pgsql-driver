<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Fake;

use Doctrine\DBAL\Driver\Result;
use Override;

final class FakeResult implements Result
{
    private int $position = 0;

    /** @param list<list<mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    #[Override]
    public function fetchNumeric(): array|false
    {
        return $this->rows[$this->position++] ?? false;
    }

    #[Override]
    public function fetchAssociative(): array|false
    {
        $row = $this->fetchNumeric();

        if ($row === false) {
            return false;
        }

        $assoc = [];

        foreach ($row as $index => $value) {
            $assoc['c' . $index] = $value;
        }

        return $assoc;
    }

    #[Override]
    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();

        return $row === false ? false : $row[0];
    }

    #[Override]
    public function fetchAllNumeric(): array
    {
        $rows = array_slice($this->rows, $this->position);
        $this->position = count($this->rows);

        return $rows;
    }

    #[Override]
    public function fetchAllAssociative(): array
    {
        $rows = [];

        while (($row = $this->fetchAssociative()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    #[Override]
    public function fetchFirstColumn(): array
    {
        return array_map(static fn(array $row): mixed => $row[0], $this->fetchAllNumeric());
    }

    #[Override]
    public function rowCount(): int|string
    {
        return count($this->rows);
    }

    #[Override]
    public function columnCount(): int
    {
        return count($this->rows[0] ?? []);
    }

    #[Override]
    public function free(): void
    {
        $this->position = count($this->rows);
    }
}
