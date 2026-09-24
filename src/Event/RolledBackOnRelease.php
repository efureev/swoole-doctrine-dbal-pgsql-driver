<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Event;

use Override;

/**
 * Корутина отдала соединение с открытой транзакцией: пул откатил её. Это почти всегда баг приложения
 * (исключение между begin и commit без rollBack) — событие и warning в логе нужны, чтобы его найти.
 */
final readonly class RolledBackOnRelease implements PoolEvent
{
    public function __construct(
        public string $poolLabel,
        public int $cid,
        public int $connectionId,
        public bool $viaDefer,
    ) {
    }

    #[Override]
    public function poolLabel(): string
    {
        return $this->poolLabel;
    }
}
