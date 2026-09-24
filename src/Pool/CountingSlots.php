<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

use LogicException;
use Override;

/**
 * Семафор без ожидания: для тестов и прямого режима. Исчерпание = мгновенный TimedOut.
 */
final class CountingSlots implements Slots
{
    private int $available;
    private bool $closed = false;

    public function __construct(private readonly int $size)
    {
        $this->available = $size;
    }

    #[Override]
    public function acquire(float $timeout): SlotResult
    {
        if ($this->closed) {
            return SlotResult::Closed;
        }

        if ($this->available === 0) {
            return SlotResult::TimedOut;
        }

        $this->available--;

        return SlotResult::Acquired;
    }

    #[Override]
    public function tryAcquire(): bool
    {
        return $this->acquire(0.0) === SlotResult::Acquired;
    }

    #[Override]
    public function release(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->available >= $this->size) {
            throw new LogicException('release() вызван для слота, который не брали.');
        }

        $this->available++;
    }

    #[Override]
    public function available(): int
    {
        return $this->closed ? 0 : $this->available;
    }

    #[Override]
    public function waiting(): int
    {
        return 0;
    }

    #[Override]
    public function close(): void
    {
        $this->closed = true;
    }
}
