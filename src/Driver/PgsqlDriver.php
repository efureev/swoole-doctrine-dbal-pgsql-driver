<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Driver;

use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\PDO\Connection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Override;
use PDO;
use PDOException;
use Pdo\Pgsql;
use SensitiveParameter;
use SwooleDoctrinePool\Exception\InvalidConfigurationException;

use function class_exists;
use function is_array;
use function is_int;
use function is_string;

/**
 * Подключение через PDO pgsql без штатного Doctrine\DBAL\Driver\PDO\PgSQL\Driver: тот на PHP >= 8.4
 * обращается к классу Pdo\Pgsql, которого во встроенном в Swoole драйвере pdo_pgsql нет. Логика та же:
 * DSN из параметров DBAL, ERRMODE_EXCEPTION, отключённые серверные prepared statements, SET NAMES.
 *
 * @psalm-suppress InternalClass, InternalMethod Driver\PDO\Connection и Exception::new — то, чем пользуется сам DBAL
 */
final class PgsqlDriver extends AbstractPostgreSQLDriver
{
    /** Значение PDO::PGSQL_ATTR_DISABLE_PREPARES (в PHP 8.5 константа deprecated в пользу Pdo\Pgsql). */
    private const int ATTR_DISABLE_PREPARES = 1000;

    /** @var list<string> */
    private const array DSN_PARAMS = [
        'host',
        'port',
        'dbname',
        'sslmode',
        'sslrootcert',
        'sslcert',
        'sslkey',
        'sslcrl',
        'application_name',
        'gssencmode',
    ];

    #[Override]
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        if (!empty($params['persistent'])) {
            throw InvalidConfigurationException::persistentNotAllowed();
        }

        foreach (['user', 'password'] as $key) {
            if (isset($params[$key]) && !is_string($params[$key])) {
                throw InvalidConfigurationException::invalidValue($key, $params[$key], 'строка');
            }
        }

        $options = [];

        if (isset($params['driverOptions']) && is_array($params['driverOptions'])) {
            foreach ($params['driverOptions'] as $attribute => $value) {
                if (is_int($attribute)) {
                    $options[$attribute] = $value;
                }
            }
        }

        try {
            $pdo = new PDO(self::dsn($params), $params['user'] ?? '', $params['password'] ?? '', $options);
        } catch (PDOException $e) {
            throw Exception::new($e);
        }

        $disablePrepares = class_exists(Pgsql::class) ? Pgsql::ATTR_DISABLE_PREPARES : self::ATTR_DISABLE_PREPARES;

        if (!isset($options[$disablePrepares]) || $options[$disablePrepares] === true) {
            $pdo->setAttribute($disablePrepares, true);
        }

        $connection = new Connection($pdo);

        if (isset($params['charset']) && is_string($params['charset'])) {
            // SET NAMES, а не client_encoding в DSN: последнее ломает pgbouncer.
            $connection->exec("SET NAMES '" . $params['charset'] . "'");
        }

        return $connection;
    }

    /** @param array<string, mixed> $params */
    public static function dsn(array $params): string
    {
        $dsn = 'pgsql:';

        foreach (self::DSN_PARAMS as $name) {
            $value = $params[$name] ?? null;

            if ($value === null || $value === '' || (!is_string($value) && !is_int($value))) {
                continue;
            }

            $dsn .= $name . '=' . $value . ';';
        }

        return $dsn;
    }
}
