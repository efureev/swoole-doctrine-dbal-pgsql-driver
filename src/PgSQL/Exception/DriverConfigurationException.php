<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL\Exception;

use Doctrine\DBAL\Driver\AbstractException;

/** @psalm-immutable */
class DriverConfigurationException extends AbstractException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
