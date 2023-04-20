<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL\Events;

use Swoole\Packages\Doctrine\DBAL\PgSQL\ConnectionPoolInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class PoolCreated extends Event
{
    public function __construct(public readonly ConnectionPoolInterface $pool)
    {
    }
}
