<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Stats;

use Override;
use SwooleDoctrinePool\Pool\PoolRegistry;

final readonly class RegistryStatsProvider implements PoolStatsProviderInterface
{
    public function __construct(private PoolRegistry $registry)
    {
    }

    #[Override]
    public function snapshot(): array
    {
        return $this->registry->stats();
    }
}
