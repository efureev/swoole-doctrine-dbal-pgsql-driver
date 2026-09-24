<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Exception;

use Doctrine\DBAL\Driver\AbstractException;

use function sprintf;

/**
 * SQLSTATE 08001 (unable to establish connection): DBAL-конвертер пакета превращает его в PoolExhaustedException.
 */
final class AcquireTimeoutException extends AbstractException implements Exception
{
    public static function afterWaiting(string $poolLabel, float $waited, int $size, int $waiting): self
    {
        return new self(
            sprintf(
                'Пул %s: за %.3f с не освободилось ни одно из %d соединений (ещё ждут: %d). Увеличьте size, '
                . 'сократите время удержания соединения запросом или поднимите acquire_timeout.',
                $poolLabel,
                $waited,
                $size,
                $waiting,
            ),
            '08001',
        );
    }
}
