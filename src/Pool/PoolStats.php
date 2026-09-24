<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

/**
 * Снимок состояния пула. Инварианты: inUse + connecting + свободные слоты == size; open() <= size.
 */
final readonly class PoolStats
{
    public function __construct(
        public string $label,
        public int $size,
        public int $idle,
        public int $inUse,
        public int $connecting,
        public int $waiting,
        public int $createdTotal,
        public int $closedTotal,
        public int $acquiredTotal,
        public int $acquireTimeoutsTotal,
        public int $rollbacksOnReleaseTotal,
        public int $validationFailuresTotal,
    ) {
    }

    public function open(): int
    {
        return $this->idle + $this->inUse + $this->connecting;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'size' => $this->size,
            'idle' => $this->idle,
            'in_use' => $this->inUse,
            'connecting' => $this->connecting,
            'waiting' => $this->waiting,
            'created_total' => $this->createdTotal,
            'closed_total' => $this->closedTotal,
            'acquired_total' => $this->acquiredTotal,
            'acquire_timeouts_total' => $this->acquireTimeoutsTotal,
            'rollbacks_on_release_total' => $this->rollbacksOnReleaseTotal,
            'validation_failures_total' => $this->validationFailuresTotal,
        ];
    }
}
