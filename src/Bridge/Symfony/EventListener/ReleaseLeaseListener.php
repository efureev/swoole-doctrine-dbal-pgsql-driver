<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Bridge\Symfony\EventListener;

use SwooleDoctrinePool\Pool\PoolRegistry;

/**
 * Отдаёт соединение в пул, как только ответ отправлен: под swoole-runtime-bundle kernel.terminate
 * выполняется в корутине запроса. Без события (или вне корутины) lease всё равно освободит
 * Coroutine::defer — этот слушатель лишь делает это раньше.
 *
 * Консольная команда под ConsoleApplication тоже идёт в корутине, но после console.terminate процесс
 * завершается: пулы закрываются целиком — таймер обслуживания (если включён) иначе не даст
 * Coroutine\run() вернуться, и команда зависнет.
 */
final readonly class ReleaseLeaseListener
{
    public function __construct(private PoolRegistry $registry)
    {
    }

    public function onTerminate(): void
    {
        $this->registry->releaseCurrentCoroutine();
    }

    public function onConsoleTerminate(): void
    {
        $this->registry->releaseCurrentCoroutine();
        $this->registry->closeAll();
    }
}
