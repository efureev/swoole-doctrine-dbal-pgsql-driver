<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Exception;

use Doctrine\DBAL\Exception\ConnectionException;

/**
 * Исключение уровня обёртки DBAL: приложение ловит его как ConnectionException и отвечает 503,
 * не путая с ошибкой запроса.
 */
final class PoolExhaustedException extends ConnectionException implements Exception
{
}
