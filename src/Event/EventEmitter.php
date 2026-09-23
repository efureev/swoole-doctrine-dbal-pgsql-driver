<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Event;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Null-safe обёртка над PSR-14 и PSR-3. Событие строится лениво (без диспетчера — ноль аллокаций),
 * а исключение слушателя не может сорвать release, который выполняется в defer.
 */
final class EventEmitter
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function enabled(): bool
    {
        return $this->dispatcher !== null;
    }

    /** @param Closure(): PoolEvent $factory */
    public function emit(Closure $factory): void
    {
        if ($this->dispatcher === null) {
            return;
        }

        try {
            $this->dispatcher->dispatch($factory());
        } catch (Throwable $e) {
            $this->logger->error('swoole_pool: слушатель события бросил исключение', [
                'exception' => $e,
            ]);
        }
    }
}
