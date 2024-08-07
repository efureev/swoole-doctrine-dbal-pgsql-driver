<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL\Exception;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Throwable;

/** @psalm-immutable */
class DriverException extends AbstractException implements DBALDriverException
{
    use ExceptionFromConnectionTrait;

    public function __construct(
        string $message = '',
        private readonly ?string $errorCode = null,
        private readonly ?string $sqlState = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $sqlState, $code, $previous);
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getSQLState(): ?string
    {
        return $this->sqlState;
    }
}
