<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

use SwooleDoctrinePool\Config\PoolConfig;

use function hash;
use function is_array;
use function is_scalar;
use function ksort;
use function serialize;
use function sprintf;

/**
 * Идентификатор пула: два DBAL-соединения с одинаковыми параметрами подключения делят один пул,
 * с разными — никогда. Считается из тех же $params, что получают и драйвер, и обёртка.
 */
final readonly class PoolKey
{
    /** @var list<string> */
    private const array CONNECTION_PARAMS = [
        'host',
        'port',
        'dbname',
        'user',
        'password',
        'charset',
        'sslmode',
        'sslrootcert',
        'sslcert',
        'sslkey',
        'sslcrl',
        'application_name',
        'gssencmode',
        'unix_socket',
    ];

    public function __construct(
        public string $hash,
        public string $label,
    ) {
    }

    /** @param array<string, mixed> $params */
    public static function fromParams(array $params): self
    {
        $identity = [];

        foreach (self::CONNECTION_PARAMS as $name) {
            if (isset($params[$name]) && is_scalar($params[$name])) {
                $identity[$name] = $params[$name];
            }
        }

        $driverOptions = $params['driverOptions'] ?? [];

        if (is_array($driverOptions)) {
            unset($driverOptions[PoolConfig::OPTIONS_KEY]);
            ksort($driverOptions);
            $identity['driverOptions'] = $driverOptions;
        }

        ksort($identity);

        return new self(
            hash('xxh128', serialize($identity)),
            sprintf(
                '%s@%s:%s/%s',
                self::scalar($params, 'user', ''),
                self::scalar($params, 'host', self::scalar($params, 'unix_socket', 'localhost')),
                self::scalar($params, 'port', '5432'),
                self::scalar($params, 'dbname', ''),
            ),
        );
    }

    /** @param array<string, mixed> $params */
    private static function scalar(array $params, string $key, string $default): string
    {
        $value = $params[$key] ?? null;

        return is_scalar($value) ? (string)$value : $default;
    }
}
