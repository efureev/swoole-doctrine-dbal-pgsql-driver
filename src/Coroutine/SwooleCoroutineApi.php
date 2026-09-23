<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Coroutine;

use ArrayAccess;
use Closure;
use Override;
use Swoole\Coroutine;
use Swoole\Runtime;
use Swoole\Timer;
use SwooleDoctrinePool\Exception\UnsupportedRuntimeException;

use function defined;
use function extension_loaded;
use function max;
use function round;
use function usleep;

final class SwooleCoroutineApi implements CoroutineApi
{
    public static function create(): CoroutineApi
    {
        return extension_loaded('swoole') ? new self() : new NoCoroutineApi();
    }

    #[Override]
    public function cid(): int
    {
        return Coroutine::getCid();
    }

    #[Override]
    public function inCoroutine(): bool
    {
        return Coroutine::getCid() > 0;
    }

    #[Override]
    public function context(): ArrayAccess
    {
        $context = Coroutine::getContext();

        if ($context === null) {
            throw UnsupportedRuntimeException::notInCoroutine('Контекст корутины');
        }

        /** @var ArrayAccess<string, mixed> $context */
        return $context;
    }

    #[Override]
    public function defer(Closure $callback): void
    {
        if (Coroutine::getCid() <= 0) {
            throw UnsupportedRuntimeException::notInCoroutine('Coroutine::defer()');
        }

        Coroutine::defer($callback);
    }

    #[Override]
    public function sleep(float $seconds): void
    {
        if (Coroutine::getCid() > 0) {
            Coroutine::sleep($seconds);

            return;
        }

        usleep((int)round($seconds * 1_000_000.0));
    }

    #[Override]
    public function tick(float $seconds, Closure $callback): ?int
    {
        $id = Timer::tick(max(1, (int)round($seconds * 1000.0)), static fn(): mixed => $callback());

        return $id === false ? null : $id;
    }

    #[Override]
    public function clearTimer(int $timerId): void
    {
        Timer::clear($timerId);
    }

    #[Override]
    public function pdoHookEnabled(): bool
    {
        if (!defined('SWOOLE_HOOK_PDO_PGSQL')) {
            return false;
        }

        $flags = Runtime::getHookFlags();

        return ($flags & SWOOLE_HOOK_PDO_PGSQL) > 0;
    }
}
