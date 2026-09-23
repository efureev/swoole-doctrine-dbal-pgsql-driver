<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Event;

use Override;

final readonly class ConnectionOpened implements PoolEvent
{
    public function __construct(
        public string $poolLabel,
        public int $connectionId,
        public float $openSeconds,
    ) {
    }

    #[Override]
    public function poolLabel(): string
    {
        return $this->poolLabel;
    }
}
