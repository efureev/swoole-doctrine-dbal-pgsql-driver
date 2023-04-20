<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use function time;

final class ConnectionStats
{
    public function __construct(
        public int $lastInteraction,
        public int $counter,
        private readonly ?int $ttl = null,
        private readonly ?int $counterLimit = null,
    ) {
    }

    public function isOverdue(): bool
    {
        if (
            ($this->counterLimit === null || $this->counterLimit === 0) &&
            ($this->ttl === null || $this->ttl === 0)
        ) {
            return false;
        }
        $counterOverflow = $this->counterLimit !== null && $this->counter > $this->counterLimit;
        $ttlOverdue = $this->ttl !== null && time() - $this->lastInteraction > $this->ttl;

        return $counterOverflow || $ttlOverdue;
    }

    public function counterInc(): void
    {
        $this->counter++;
    }
}
