<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Closure;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\ParameterType;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\ConnectionException;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\DriverException;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\PingException;
use Swoole\Packages\Doctrine\DBAL\SQLParserUtils;
use function strlen;
use function substr;

class ConnectionDirect implements ConnectionInterface
{
    public function __construct(private Closure $connectionFactory)
    {
    }

    private ?ConnectionStats $stats = null;

    public function connectionStats(): ?ConnectionStats
    {
        return $this->stats;
    }

    protected function buildEmptyConnectionStats(): ?ConnectionStats
    {
        return $this->stats = new ConnectionStats(0, 0);
    }


    /**
     * {@inheritdoc}
     *
     * @throws DriverException
     */
    public function prepare(string $sql): Statement
    {
        $i = 1;
        $posShift = 0;

        $phPos = SQLParserUtils::getPlaceholderPositions($sql);
        foreach ($phPos as $pos) {
            $placeholder = '$' . $i;
            $sql = substr($sql, 0, (int)$pos + $posShift)
                . $placeholder
                . substr($sql, (int)$pos + $posShift + 1);
            $posShift += strlen($placeholder) - 1;
            $i++;
        }

        $connection = $this->getNativeConnection();

        return new Statement($connection, $sql, $this->connectionStats());
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $sql): Result
    {
        $connection = $this->getNativeConnection();

        $stmt = $connection->query($sql);

        $stats = $this->connectionStats();
        if ($stats instanceof ConnectionStats) {
            $stats->counterInc();
        }

        if (!$stmt) {
            throw ConnectionException::fromConnection($connection);
        }

        return new Result($connection, $stmt);
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $value
     * @param int $type
     */
    public function quote($value, $type = ParameterType::STRING): string
    {
        return "'" . $this->getNativeConnection()->escape($value) . "'";
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $sql): int
    {
        $pgConn = $this->getNativeConnection();
        $stmt = $pgConn->query($sql);

        $stats = $this->connectionStats();
        if ($stats instanceof ConnectionStats) {
            $stats->counterInc();
        }

        if (!$stmt->execute()) {
            throw ConnectionException::fromConnection($pgConn);
        }

        return $stmt->affectedRows();
    }

    /**
     * {@inheritdoc}
     *
     * @param string|null $name
     */
    public function lastInsertId($name = null): int|string
    {
        $stmt = !empty($name)
            ? $this->query("SELECT CURRVAL('$name')")
            : $this->query('SELECT LASTVAL()');
        $result = $stmt->fetchOne();
        if (is_string($result) || is_int($result)) {
            return (string)$result;
        }

        throw new DriverException('Failed to retrieve the last insert ID');
    }


    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): void
    {
        $this->getNativeConnection()->query('START TRANSACTION');
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): void
    {
        $stmt = $this->getNativeConnection()->query('COMMIT');

        if ($stmt === false) {
            throw new DriverException('Failed to commit the transaction');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack(): void
    {
        $stmt = $this->getNativeConnection()->query('ROLLBACK');

        if ($stmt === false) {
            throw new DriverException('Failed to rollback the transaction');
        }
    }

    public function errorCode(): int
    {
        return (int)$this->getNativeConnection()->errCode;
    }

    public function errorInfo(): string
    {
        return (string)$this->getNativeConnection()->error;
    }

    public function getNativeConnection(): PostgreSQL
    {
        $this->buildEmptyConnectionStats();

//        if ($this->connectionFactory === null) {
//            throw new DriverConfigurationException('Missing Connection factory in: ' . static::class);
//        }
        return ($this->connectionFactory)();
    }

    /**
     * @throws PingException
     */
    public function ping(PostgreSQL $connection): void
    {
        $stmt = $connection->query('SELECT 1');
        $affectedRows = $stmt !== false ? $stmt->affectedRows() : 0;

        if ($affectedRows !== 1) {
            throw new PingException();
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     */
    public function getServerVersion(): string
    {
        $stmt = $this->query('SHOW server_version');
        $result = $stmt->fetchOne();

        if (is_string($result)) {
            return $result;
        }

        throw new DriverException('Failed to retrieve the server version');
    }
}
