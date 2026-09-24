<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Event;

/**
 * Все события пакета — неизменяемые PSR-14 объекты; диспетчер Symfony реализует PSR-14, подписка обычная.
 */
interface PoolEvent
{
    public function poolLabel(): string;
}
