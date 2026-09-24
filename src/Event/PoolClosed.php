<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Event;

use Override;
use SwooleDoctrinePool\Pool\PoolStats;

final readonly class PoolClosed implements PoolEvent
{
    public function __construct(
        public string $poolLabel,
        public PoolStats $stats,
    ) {
    }

    #[Override]
    public function poolLabel(): string
    {
        return $this->poolLabel;
    }
}
