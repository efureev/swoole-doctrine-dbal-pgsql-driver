<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Swoole\Coroutine\PostgreSQL;
use Swoole\Timer;

use function array_map;
use function gc_collect_cycles;

class Scaler
{
    private const DOWNSCALE_TICK_FREQUENCY = 36000000; // 1 hour in milliseconds

    private ?int $timerId = null;

    public function __construct(
        private ConnectionPoolInterface $pool,
        private int $tickFrequency = self::DOWNSCALE_TICK_FREQUENCY,
    ) {
    }

    public function run(): void
    {
        if ($this->timerId) {
            return;
        }
        $this->timerId = Timer::tick(
            $this->tickFrequency,
            fn() => $this->downscale()
        ) ?: null;
    }

    /** @psalm-suppress UnusedVariable */
    private function downscale(): void
    {
        $poolLength = $this->pool->length();
        /** @psalm-var  PostgreSQL[] $connections */
        $connections = [];
        while ($poolLength > 0) {
            [$connection, $connectionStats] = $this->pool->get(1);
            /** connection never null if pool capacity > 0 */
            if (!$connection) {
                return;
            }
            $connections[] = $connection;
            $poolLength--;
        }
        array_map(fn(PostgreSQL $connection) => $this->pool->put($connection), $connections);
        gc_collect_cycles();
    }

    public function close(): void
    {
        if (!$this->timerId) {
            return;
        }
        Timer::clear($this->timerId);
    }
}
