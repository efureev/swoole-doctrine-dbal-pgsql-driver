<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

use Doctrine\DBAL\Driver as DoctrineDriver;
use Override;
use PDO;
use SensitiveParameter;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\Exception\InvalidConfigurationException;
use SwooleDoctrinePool\Exception\UnsupportedRuntimeException;

use function is_array;

/**
 * Открывает соединения через штатный DBAL-драйвер pdo_pgsql: DSN, ATTR_DISABLE_PREPARES, SET NAMES
 * и ERRMODE_EXCEPTION — ровно как без пула. Опции пула из driverOptions вырезаются, int-ключи
 * (PDO::ATTR_*) доходят до PDO.
 */
final class DbalConnectionFactory implements ConnectionFactory
{
    /** @var array<string, mixed> */
    private readonly array $params;

    /** @param array<string, mixed> $params */
    public function __construct(
        private readonly DoctrineDriver $inner,
        #[SensitiveParameter]
        array $params,
        private readonly Clock $clock,
    ) {
        if (!empty($params['persistent'])) {
            throw InvalidConfigurationException::persistentNotAllowed();
        }

        unset($params['wrapperClass'], $params['driverClass']);

        if (isset($params['driverOptions']) && is_array($params['driverOptions'])) {
            unset($params['driverOptions'][PoolConfig::OPTIONS_KEY]);
        }

        $this->params = $params;
    }

    #[Override]
    public function open(int $id): PhysicalConnection
    {
        $startedAt = $this->clock->now();
        $connection = $this->inner->connect($this->params);
        $native = $connection->getNativeConnection();

        if (!$native instanceof PDO) {
            throw UnsupportedRuntimeException::notPdo($native);
        }

        return new PhysicalConnection(
            $id,
            $native,
            $connection,
            (string)$native->getAttribute(PDO::ATTR_SERVER_VERSION),
            $startedAt,
        );
    }
}
