<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Exception;

use Doctrine\DBAL\Driver\AbstractException;

use function sprintf;

final class PoolClosedException extends AbstractException implements Exception
{
    public static function forPool(string $poolLabel): self
    {
        return new self(
            sprintf('Пул %s закрыт (воркер останавливается): новые соединения не выдаются.', $poolLabel),
            '08001',
        );
    }
}
