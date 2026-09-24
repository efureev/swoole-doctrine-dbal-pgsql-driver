<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Fake;

use Override;
use SwooleDoctrinePool\Pool\Clock;

final class FakeClock implements Clock
{
    public function __construct(public float $now = 1000.0)
    {
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    #[Override]
    public function now(): float
    {
        return $this->now;
    }
}
