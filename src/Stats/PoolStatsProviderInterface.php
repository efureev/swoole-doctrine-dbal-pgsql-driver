<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Stats;

use SwooleDoctrinePool\Pool\PoolStats;

/**
 * Точка подключения метрик (Prometheus, health-check): снимок всех пулов текущего воркера.
 */
interface PoolStatsProviderInterface
{
    /** @return array<string, PoolStats> label пула => снимок */
    public function snapshot(): array;
}
