<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Driver;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;
use Override;
use SwooleDoctrinePool\Exception\AcquireTimeoutException;
use SwooleDoctrinePool\Exception\ConnectionLostException;
use SwooleDoctrinePool\Exception\PoolClosedException;
use SwooleDoctrinePool\Exception\PoolExhaustedException;
use SwooleDoctrinePool\Pool\LostConnectionClassifier;

/**
 * Поверх штатного PostgreSQL-конвертера: потерю соединения поднимает до ConnectionLost (штатный
 * знает лишь два текста и SQLSTATE 08006 считает обычной ConnectionException). DBAL на ConnectionLost
 * сам зовёт close() обёртки, то есть освобождает lease и уничтожает мёртвое соединение.
 */
final class PoolExceptionConverter implements ExceptionConverter
{
    public function __construct(
        private readonly ExceptionConverter $inner,
        private readonly LostConnectionClassifier $classifier,
    ) {
    }

    #[Override]
    public function convert(Exception $exception, ?Query $query): DriverException
    {
        if ($exception instanceof ConnectionLostException) {
            return new ConnectionLost($exception, $query);
        }

        if ($exception instanceof AcquireTimeoutException || $exception instanceof PoolClosedException) {
            return new PoolExhaustedException($exception, $query);
        }

        $converted = $this->inner->convert($exception, $query);

        if (!$converted instanceof ConnectionLost && $this->classifier->isLost($exception)) {
            return new ConnectionLost($exception, $query);
        }

        return $converted;
    }
}
