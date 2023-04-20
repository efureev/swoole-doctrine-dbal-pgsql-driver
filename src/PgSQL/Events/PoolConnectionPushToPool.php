<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL\Events;

use Symfony\Contracts\EventDispatcher\Event;

final class PoolConnectionPushToPool extends Event
{
    public function __construct(
        public readonly int $capacity,
        public readonly int $chanLength,
        public readonly array $chanStats
    ) {
    }
}
