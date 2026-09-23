<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

use Override;

use function hrtime;

final class MonotonicClock implements Clock
{
    #[Override]
    public function now(): float
    {
        return (float)hrtime(true) / 1e9;
    }
}
