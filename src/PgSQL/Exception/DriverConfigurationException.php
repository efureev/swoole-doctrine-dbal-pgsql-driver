<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL\Exception;

use Doctrine\DBAL\Exception;

class DriverConfigurationException extends Exception
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
