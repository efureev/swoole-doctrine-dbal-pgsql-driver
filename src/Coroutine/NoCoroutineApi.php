<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Coroutine;

use ArrayAccess;
use Closure;
use Override;
use SwooleDoctrinePool\Exception\UnsupportedRuntimeException;

use function round;
use function usleep;

/**
 * Рантайм без Swoole (обычный CLI, phpunit без расширения): драйвер работает в прямом режиме,
 * пул и таймеры не создаются.
 */
final class NoCoroutineApi implements CoroutineApi
{
    #[Override]
    public function cid(): int
    {
        return -1;
    }

    #[Override]
    public function inCoroutine(): bool
    {
        return false;
    }

    #[Override]
    public function context(): ArrayAccess
    {
        throw UnsupportedRuntimeException::notInCoroutine('Контекст корутины');
    }

    #[Override]
    public function defer(Closure $callback): void
    {
        throw UnsupportedRuntimeException::notInCoroutine('Coroutine::defer()');
    }

    #[Override]
    public function sleep(float $seconds): void
    {
        usleep((int)round($seconds * 1_000_000.0));
    }

    #[Override]
    public function tick(float $seconds, Closure $callback): ?int
    {
        return null;
    }

    #[Override]
    public function clearTimer(int $timerId): void
    {
    }

    #[Override]
    public function pdoHookEnabled(): bool
    {
        return false;
    }
}
