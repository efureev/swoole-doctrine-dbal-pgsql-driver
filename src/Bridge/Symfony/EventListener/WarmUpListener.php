<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Bridge\Symfony\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use SwooleDoctrinePool\Pool\PoolRegistry;
use Throwable;

/**
 * На старте воркера (swoole.worker_start) открывает min_idle соединений заранее, чтобы первые
 * запросы не платили за коннект. Пулы с min_idle = 0 не трогает — они создаются лениво.
 */
final readonly class WarmUpListener
{
    /** @param list<string> $connectionNames */
    public function __construct(
        private ?ManagerRegistry $doctrine,
        private PoolRegistry $registry,
        private array $connectionNames,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function onWorkerStart(): void
    {
        if ($this->doctrine === null || !$this->registry->api()->inCoroutine()) {
            return;
        }

        foreach ($this->connectionNames as $name) {
            $connection = $this->doctrine->getConnection($name);

            if (!$connection instanceof CoroutineSafeConnection) {
                continue;
            }

            /** @psalm-suppress InternalMethod параметры соединения — единственный способ найти его пул */
            $params = $connection->getParams();

            if (PoolConfig::fromDbalParams($params)->minIdle === 0) {
                continue;
            }

            $inner = $this->registry->innerDriver();

            if ($inner === null) {
                continue;
            }

            try {
                (new Driver($inner, $this->registry))->poolFor($params)->warmUp();
            } catch (Throwable $e) {
                $this->logger?->warning('swoole_pool: прогрев пула не удался', [
                    'connection' => $name,
                    'exception' => $e,
                ]);
            }
        }
    }
}
