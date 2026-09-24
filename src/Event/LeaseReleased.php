<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Event;

use Override;

final readonly class LeaseReleased implements PoolEvent
{
    public function __construct(
        public string $poolLabel,
        public int $cid,
        public int $connectionId,
        public float $heldSeconds,
        public bool $rolledBack,
        public bool $viaDefer,
    ) {
    }

    #[Override]
    public function poolLabel(): string
    {
        return $this->poolLabel;
    }
}
