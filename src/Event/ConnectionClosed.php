<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Event;

use Override;
use SwooleDoctrinePool\Pool\CloseReason;

final readonly class ConnectionClosed implements PoolEvent
{
    public function __construct(
        public string $poolLabel,
        public int $connectionId,
        public CloseReason $reason,
        public float $ageSeconds,
        public int $uses,
    ) {
    }

    #[Override]
    public function poolLabel(): string
    {
        return $this->poolLabel;
    }
}
