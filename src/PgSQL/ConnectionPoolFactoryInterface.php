<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Psr\EventDispatcher\EventDispatcherInterface;

interface ConnectionPoolFactoryInterface
{
    public function factory(ConnectionPoolConfig $params): ConnectionPoolInterface;

    public function getEventDispatcher(): EventDispatcherInterface;
}
