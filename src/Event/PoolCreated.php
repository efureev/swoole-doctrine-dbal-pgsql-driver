<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Event;

use Override;
use SwooleDoctrinePool\Config\PoolConfig;

final readonly class PoolCreated implements PoolEvent
{
    public function __construct(
        public string $poolLabel,
        public PoolConfig $config,
    ) {
    }

    #[Override]
    public function poolLabel(): string
    {
        return $this->poolLabel;
    }
}
