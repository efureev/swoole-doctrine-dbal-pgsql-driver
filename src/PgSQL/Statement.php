<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Coroutine\PostgreSQLStatement;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\DriverException as SwooleDriverException;
use function is_array;
use function is_bool;

final class Statement implements StatementInterface
{
    private array $params = [];
    private PostgreSQLStatement $stmt;

    public function __construct(private PostgreSQL $connection, string $sql, private ?ConnectionStats $stats = null)
    {
        $stmt = $this->connection->prepare($sql);

        if ($stmt === false) {
            throw SwooleDriverException::fromConnection($this->connection);
        }

        $this->stmt = $stmt;
    }

    /**
     * {@inheritdoc}
     *
     * @param int|string $param
     * @param mixed $value
     * @param ParameterType $type
     */
    public function bindValue($param, $value, $type = ParameterType::STRING): void
    {
        $this->params[$param] = $this->escapeValue($value, $type);
    }

    /**
     * @param int|string $param
     * @param mixed $variable
     * @param ParameterType $type
     * @param int|null $length
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): void
    {
        $this->bindValue($param, $variable, $type);
    }

    /**
     * @param mixed|null $params
     * @throws SwooleDriverException
     * @psalm-suppress ImplementedReturnTypeMismatch
     */
    public function execute($params = []): ResultInterface
    {
        $mergedParams = $this->params;
        if (!empty($params)) {
            $params = is_array($params) ? $params : [$params];
            /** @psalm-var mixed|null $param */
            foreach ($params as $key => $param) {
                /** @psalm-suppress MixedAssignment */
                $mergedParams[$key] = $this->escapeValue($param);
            }
        }

        $this->stmt->execute($mergedParams);
        if ($this->stats instanceof ConnectionStats) {
            $this->stats->counterInc();
        }

        return new Result($this->connection, $this->stmt);
    }

    public function errorCode(): int
    {
        return (int)$this->connection->errCode;
    }

    public function errorInfo(): string
    {
        return (string)$this->connection->error;
    }

    private function escapeValue(mixed $value, ParameterType $type = ParameterType::STRING): ?string
    {
        if ($value !== null && (is_bool($value) || $type === ParameterType::BOOLEAN)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if ($value === null || $type === ParameterType::NULL) {
            return null;
        }

        return (string)$value;
    }
}
