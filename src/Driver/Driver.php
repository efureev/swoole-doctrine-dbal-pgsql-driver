<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Driver;

use Doctrine\DBAL\Driver as DoctrineDriver;
use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Override;
use SensitiveParameter;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Exception\InvalidConfigurationException;
use SwooleDoctrinePool\Pool\DbalConnectionFactory;
use SwooleDoctrinePool\Pool\LostConnectionClassifier;
use SwooleDoctrinePool\Pool\Pool;
use SwooleDoctrinePool\Pool\PoolKey;
use SwooleDoctrinePool\Pool\PoolRegistry;

use function is_a;
use function is_string;

/**
 * DBAL-драйвер с пулом. `new Driver()` без аргументов годится для параметра driverClass;
 * общий на воркер PoolRegistry подставляет PoolDriverMiddleware.
 */
final class Driver extends AbstractPostgreSQLDriver
{
    private readonly LostConnectionClassifier $classifier;

    public function __construct(
        private readonly DoctrineDriver $inner = new PgsqlDriver(),
        private readonly PoolRegistry $registry = new PoolRegistry(),
    ) {
        $this->classifier = new LostConnectionClassifier();
        $registry->rememberInnerDriver($inner);
    }

    public function withRegistry(PoolRegistry $registry): self
    {
        return new self($this->inner, $registry);
    }

    public function inner(): DoctrineDriver
    {
        return $this->inner;
    }

    public function registry(): PoolRegistry
    {
        return $this->registry;
    }

    /**
     * Дёшево: сети нет, пул создаётся при первом запросе из корутины.
     *
     * @param array<string, mixed> $params
     */
    #[Override]
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): VirtualConnection {
        self::assertWrapperClass($params);

        $key = PoolKey::fromParams($params);
        $config = PoolConfig::fromDbalParams($params);
        $factory = new DbalConnectionFactory($this->inner, $params, $this->registry->clock());

        return new VirtualConnection(
            $key,
            fn(): Pool => $this->registry->poolFor($key, $config, $factory),
            $factory,
            $this->registry->binder(),
            $this->registry->api(),
            $this->classifier,
        );
    }

    /**
     * Пул для параметров соединения без запроса к БД: для прогрева на старте воркера.
     *
     * @param array<string, mixed> $params
     */
    public function poolFor(
        #[SensitiveParameter]
        array $params,
    ): Pool {
        return $this->registry->poolFor(
            PoolKey::fromParams($params),
            PoolConfig::fromDbalParams($params),
            new DbalConnectionFactory($this->inner, $params, $this->registry->clock()),
        );
    }

    #[Override]
    public function getExceptionConverter(): ExceptionConverter
    {
        return new PoolExceptionConverter($this->inner->getExceptionConverter(), $this->classifier);
    }

    /** @param array<string, mixed> $params */
    private static function assertWrapperClass(array $params): void
    {
        $wrapperClass = $params['wrapperClass'] ?? null;

        if (!is_string($wrapperClass) || !is_a($wrapperClass, CoroutineSafeConnection::class, true)) {
            throw InvalidConfigurationException::wrapperClassRequired(is_string($wrapperClass) ? $wrapperClass : null);
        }
    }
}
