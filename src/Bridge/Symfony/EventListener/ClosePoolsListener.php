<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Bridge\Symfony\EventListener;

use Psr\Log\LoggerInterface;
use SwooleDoctrinePool\Pool\PoolRegistry;

/**
 * Жизненный цикл воркера по строковым именам событий Swoole-рантайма (если их никто не диспатчит, слушатель молчит).
 * worker_exit (reload_async, max_request, перезапуск по памяти) приходит, пока старый воркер ещё
 * дообслуживает запросы: здесь только drain — таймеры снять, простаивающие закрыть, возвращаемые не
 * копить. Полное закрытие — на worker_stop, когда корутин уже нет. Оба вызова идемпотентны:
 * worker_exit повторяется, пока event loop не опустеет.
 */
final readonly class ClosePoolsListener
{
    public function __construct(
        private PoolRegistry $registry,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function onExit(): void
    {
        if ($this->registry->count() === 0) {
            return;
        }

        $this->registry->drainAll();
        $this->logger?->debug('swoole_pool: пулы переведены в drain — воркер завершает запросы в полёте');
    }

    public function onStop(): void
    {
        if ($this->registry->count() === 0) {
            return;
        }

        $this->registry->closeAll();
        $this->logger?->debug('swoole_pool: пулы закрыты при остановке воркера');
    }
}
