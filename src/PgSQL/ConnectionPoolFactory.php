<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\DriverConfigurationException;

class ConnectionPoolFactory implements ConnectionPoolFactoryInterface
{
    public function __construct(private ?EventDispatcherInterface $dispatcher = null)
    {
    }

    /**
     * @throws DriverConfigurationException
     */
    public function factory(ConnectionPoolConfig $params): ConnectionPoolInterface
    {
        return new ConnectionPool(
            $this->dispatcher,
            static fn(): PostgreSQL => Driver::createConnection(Driver::generateDSN($params)),
            $params->poolSize,
            $params->connectionTTL,
            $params->usedTimes,
        );
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->em;
    }
}
