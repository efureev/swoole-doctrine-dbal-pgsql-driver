<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Fake;

use ArrayAccess;
use ArrayObject;
use Closure;
use Override;
use SwooleDoctrinePool\Coroutine\CoroutineApi;
use SwooleDoctrinePool\Exception\UnsupportedRuntimeException;

/**
 * Рантайм корутин без Swoole: текущий cid переключается вручную, defer-ы копятся по cid и
 * выполняются явным finishCoroutine() в LIFO-порядке, как в Swoole.
 */
final class FakeCoroutineApi implements CoroutineApi
{
    private int $cid;
    /** @var array<int, ArrayObject<string, mixed>> */
    private array $contexts = [];
    /** @var array<int, list<Closure>> */
    private array $defers = [];
    /** @var array<int, array{float, Closure}> */
    public array $timers = [];
    private int $nextTimerId = 1;
    public bool $hookEnabled = true;
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(int $cid = 1)
    {
        $this->cid = $cid;
    }

    public function switchTo(int $cid): void
    {
        $this->cid = $cid;
    }

    public function leaveCoroutines(): void
    {
        $this->cid = -1;
    }

    /** Завершает корутину: запускает её defer-ы (LIFO) и уничтожает контекст. */
    public function finishCoroutine(int $cid): void
    {
        $previous = $this->cid;
        $this->cid = $cid;

        try {
            while (($defer = array_pop($this->defers[$cid])) !== null) {
                $defer();
            }
        } finally {
            unset($this->defers[$cid], $this->contexts[$cid]);
            $this->cid = $previous;
        }
    }

    public function pendingDefers(int $cid): int
    {
        return count($this->defers[$cid] ?? []);
    }

    public function runTimers(): void
    {
        foreach ($this->timers as [, $callback]) {
            $callback();
        }
    }

    #[Override]
    public function cid(): int
    {
        return $this->cid;
    }

    #[Override]
    public function inCoroutine(): bool
    {
        return $this->cid > 0;
    }

    #[Override]
    public function context(): ArrayAccess
    {
        if ($this->cid <= 0) {
            throw UnsupportedRuntimeException::notInCoroutine('Контекст корутины');
        }

        return $this->contexts[$this->cid] ??= new ArrayObject();
    }

    #[Override]
    public function defer(Closure $callback): void
    {
        if ($this->cid <= 0) {
            throw UnsupportedRuntimeException::notInCoroutine('Coroutine::defer()');
        }

        $this->defers[$this->cid][] = $callback;
    }

    #[Override]
    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }

    #[Override]
    public function tick(float $seconds, Closure $callback): ?int
    {
        $id = $this->nextTimerId++;
        $this->timers[$id] = [$seconds, $callback];

        return $id;
    }

    #[Override]
    public function clearTimer(int $timerId): void
    {
        unset($this->timers[$timerId]);
    }

    #[Override]
    public function pdoHookEnabled(): bool
    {
        return $this->hookEnabled;
    }
}
