<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Exception;

use Doctrine\DBAL\Driver\AbstractException;

use function sprintf;

/**
 * SQLSTATE 08006 (connection failure): конвертер пакета превращает его в Doctrine\DBAL\Exception\ConnectionLost.
 */
final class ConnectionLostException extends AbstractException implements Exception
{
    public static function brokenInTransaction(string $poolLabel, int $level): self
    {
        return new self(
            sprintf(
                'Соединение пула %s потеряно, пока была открыта транзакция (уровень %d). Транзакция откачена '
                . 'сервером; повторите операцию целиком на новом соединении.',
                $poolLabel,
                $level,
            ),
            '08006',
        );
    }
}
