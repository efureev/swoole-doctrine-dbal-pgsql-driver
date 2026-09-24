<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Event;

use Override;
use SwooleDoctrinePool\Pool\PoolStats;

final readonly class AcquireTimedOut implements PoolEvent
{
    public function __construct(
        public string $poolLabel,
        public int $cid,
        public float $waitedSeconds,
        public PoolStats $stats,
    ) {
    }

    #[Override]
    public function poolLabel(): string
    {
        return $this->poolLabel;
    }
}
