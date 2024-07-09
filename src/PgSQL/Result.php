<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Coroutine\PostgreSQLStatement;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\DriverException;
use function count;
use function is_array;

class Result implements ResultInterface
{
    public function __construct(private PostgreSQL $connection, private ?PostgreSQLStatement $stmt = null)
    {
    }

    /** {@inheritdoc} */
    public function fetchNumeric(): array|false
    {
        if ($this->stmt === null) {
            throw DriverException::fromConnection($this->connection);
        }

        return $this->stmt->fetchArray(null, SW_PGSQL_NUM);
    }

    /** {@inheritdoc} */
    public function fetchAssociative(): array|false
    {
        if ($this->stmt === null) {
            throw DriverException::fromConnection($this->connection);
        }

        $result = $this->stmt->fetchAssoc();

        if (is_array($result) && count($result) === 0) {
            $result = false;
        }

        return $result;
    }

    /** {@inheritdoc} */
    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();

        return $row === false ? false : $row[0];
    }

    /** {@inheritdoc} */
    public function fetchAllNumeric(): array
    {
        $rows = [];
        while (($row = $this->fetchNumeric()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** {@inheritdoc} */
    public function fetchAllAssociative(): array
    {
        $rows = [];
        while (($row = $this->fetchAssociative()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     *
     * @psalm-suppress MixedAssignment
     */
    public function fetchFirstColumn(): array
    {
        $rows = [];
        while (($row = $this->fetchOne()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** {@inheritdoc} */
    public function rowCount(): int
    {
        if ($this->stmt === null) {
            throw DriverException::fromConnection($this->connection);
        }

        /** @var int|false $res */
        $res = $this->stmt->affectedRows();

        if ($res === false) {
            throw DriverException::fromConnection($this->connection);
        }

        return $res;
    }

    /** {@inheritdoc} */
    public function columnCount(): int
    {
        if ($this->stmt === null) {
            throw DriverException::fromConnection($this->connection);
        }

        return $this->stmt->fieldCount();
    }

    /** {@inheritdoc} */
    public function free(): void
    {
        $this->stmt = null;
    }
}
