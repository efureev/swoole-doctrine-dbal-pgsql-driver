<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Event;

use Override;

final readonly class LeaseAcquired implements PoolEvent
{
    public function __construct(
        public string $poolLabel,
        public int $cid,
        public int $connectionId,
        public float $waitedSeconds,
        public bool $fromIdle,
    ) {
    }

    #[Override]
    public function poolLabel(): string
    {
        return $this->poolLabel;
    }
}
