<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\Config\ResetPolicy;
use SwooleDoctrinePool\Exception\InvalidConfigurationException;

#[CoversClass(PoolConfig::class)]
final class PoolConfigTest extends TestCase
{
    public function testAnEmptyArrayGivesEveryDefault(): void
    {
        $config = PoolConfig::fromArray([]);

        self::assertSame(PoolConfig::DEFAULT_SIZE, $config->size);
        self::assertSame(PoolConfig::DEFAULT_MIN_IDLE, $config->minIdle);
        self::assertSame(PoolConfig::DEFAULT_ACQUIRE_TIMEOUT, $config->acquireTimeout);
        self::assertSame((float)PoolConfig::DEFAULT_MAX_LIFETIME, $config->maxLifetime);
        self::assertSame((float)PoolConfig::DEFAULT_IDLE_TIMEOUT, $config->idleTimeout);
        self::assertSame(PoolConfig::DEFAULT_MAX_USES, $config->maxUses);
        self::assertSame(PoolConfig::DEFAULT_VALIDATE_IDLE_AFTER, $config->validateIdleAfter);
        self::assertSame(ResetPolicy::Discard, $config->resetOnRelease);
        self::assertSame((float)PoolConfig::DEFAULT_MAINTENANCE_INTERVAL, $config->maintenanceInterval);
        self::assertSame(ResetPolicy::Discard->value, PoolConfig::DEFAULT_RESET_ON_RELEASE);
    }

    public function testStringValuesFromEnvAreCoerced(): void
    {
        $config = PoolConfig::fromArray([
            'size' => '12',
            'min_idle' => '2',
            'acquire_timeout' => '2.5',
            'max_lifetime' => '0',
            'idle_timeout' => '',
            'max_uses' => '100',
            'validate_idle_after' => '0',
            'reset_on_release' => 'ROLLBACK_ONLY',
            'maintenance_interval' => '3',
        ]);

        self::assertSame(12, $config->size);
        self::assertSame(2, $config->minIdle);
        self::assertSame(2.5, $config->acquireTimeout);
        self::assertNull($config->maxLifetime, '0 для max_lifetime означает «без ограничения»');
        self::assertNull($config->idleTimeout, 'Пустая строка из env — null');
        self::assertSame(100, $config->maxUses);
        self::assertSame(0.0, $config->validateIdleAfter, '0 для validate_idle_after означает «всегда», не null');
        self::assertSame(ResetPolicy::RollbackOnly, $config->resetOnRelease);
        self::assertSame(3.0, $config->maintenanceInterval);
    }

    public function testNullDisablesValidation(): void
    {
        self::assertNull(PoolConfig::fromArray(['validate_idle_after' => null])->validateIdleAfter);
        self::assertNull(PoolConfig::fromArray(['validate_idle_after' => '~'])->validateIdleAfter);
    }

    public function testAnUnknownKeyIsRejectedToCatchTypos(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('poolSize');

        PoolConfig::fromArray(['poolSize' => 5]);
    }

    public function testMinIdleAboveSizeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        PoolConfig::fromArray(['size' => 2, 'min_idle' => 3]);
    }

    public function testSizeBelowOneIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        PoolConfig::fromArray(['size' => 0]);
    }

    public function testANonNumericStringIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        PoolConfig::fromArray(['size' => 'ten']);
    }

    public function testAnUnknownResetPolicyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        PoolConfig::fromArray(['reset_on_release' => 'truncate']);
    }

    public function testDbalParamsWithoutPoolKeyGiveDefaults(): void
    {
        $config = PoolConfig::fromDbalParams(['host' => 'db', 'driverOptions' => [\PDO::ATTR_TIMEOUT => 3]]);

        self::assertSame(PoolConfig::DEFAULT_SIZE, $config->size);
    }

    public function testDbalParamsWithPoolKeyAreRead(): void
    {
        $config = PoolConfig::fromDbalParams(['driverOptions' => ['pool' => ['size' => 4]]]);

        self::assertSame(4, $config->size);
    }

    public function testToArrayRoundTrips(): void
    {
        $config = PoolConfig::fromArray(['size' => 3, 'reset_on_release' => 'rollback_only']);

        self::assertSame($config->toArray(), PoolConfig::fromArray($config->toArray())->toArray());
        self::assertSame(PoolConfig::KEYS, array_keys($config->toArray()));
    }
}
