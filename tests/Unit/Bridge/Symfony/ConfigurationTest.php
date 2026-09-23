<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Bridge\Symfony;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Bridge\Symfony\DependencyInjection\Configuration;
use SwooleDoctrinePool\Config\PoolConfig;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function testAConnectionListedWithNullGetsEveryDefaultFromPoolConfig(): void
    {
        $config = $this->process(['connections' => ['default' => null]]);

        self::assertSame([
            'enabled' => true,
            'size' => PoolConfig::DEFAULT_SIZE,
            'min_idle' => PoolConfig::DEFAULT_MIN_IDLE,
            'acquire_timeout' => PoolConfig::DEFAULT_ACQUIRE_TIMEOUT,
            'max_lifetime' => PoolConfig::DEFAULT_MAX_LIFETIME,
            'idle_timeout' => PoolConfig::DEFAULT_IDLE_TIMEOUT,
            'max_uses' => PoolConfig::DEFAULT_MAX_USES,
            'validate_idle_after' => PoolConfig::DEFAULT_VALIDATE_IDLE_AFTER,
            'reset_on_release' => PoolConfig::DEFAULT_RESET_ON_RELEASE,
            'maintenance_interval' => PoolConfig::DEFAULT_MAINTENANCE_INTERVAL,
        ], $config['connections']['default']);
    }

    public function testEveryDefaultRoundTripsThroughPoolConfig(): void
    {
        $config = $this->process(['connections' => ['default' => []]]);
        $options = $config['connections']['default'];
        unset($options['enabled']);

        self::assertSame(PoolConfig::fromArray([])->toArray(), PoolConfig::fromArray($options)->toArray());
    }

    public function testTrueAndAnEmptyArrayAlsoEnableAConnection(): void
    {
        $config = $this->process(['connections' => ['a' => true, 'b' => []]]);

        self::assertTrue($config['connections']['a']['enabled']);
        self::assertTrue($config['connections']['b']['enabled']);
    }

    public function testFalseDisablesAConnection(): void
    {
        $config = $this->process(['connections' => ['legacy' => false]]);

        self::assertFalse($config['connections']['legacy']['enabled']);
    }

    public function testConnectionNamesAreKeptVerbatim(): void
    {
        $config = $this->process(['connections' => ['my-db_2' => null]]);

        self::assertArrayHasKey('my-db_2', $config['connections']);
    }

    public function testNoConnectionsIsValid(): void
    {
        self::assertSame([], $this->process([])['connections']);
    }

    public function testMinIdleAboveSizeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('min_idle');

        $this->process(['connections' => ['default' => ['size' => 2, 'min_idle' => 3]]]);
    }

    public function testSizeBelowOneIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['connections' => ['default' => ['size' => 0]]]);
    }

    public function testMaintenanceIntervalZeroMeansNoTimerAndNegativeIsRejected(): void
    {
        self::assertSame(0, $this->process(['connections' => ['default' => ['maintenance_interval' => 0]]])['connections']['default']['maintenance_interval']);

        $this->expectException(InvalidConfigurationException::class);
        $this->process(['connections' => ['default' => ['maintenance_interval' => -1]]]);
    }

    public function testAnUnknownResetPolicyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['connections' => ['default' => ['reset_on_release' => 'truncate']]]);
    }

    public function testValidateIdleAfterAcceptsNullZeroFloatsAndPlaceholders(): void
    {
        foreach ([null, 0, 2.5, '%env(float:X)%'] as $value) {
            $config = $this->process(['connections' => ['default' => ['validate_idle_after' => $value]]]);
            self::assertSame($value, $config['connections']['default']['validate_idle_after']);
        }
    }

    public function testValidateIdleAfterRejectsNegativeValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['connections' => ['default' => ['validate_idle_after' => -1]]]);
    }

    public function testAnUnknownKeyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['connections' => ['default' => ['poolSize' => 5]]]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{connections: array<string, array<string, mixed>>}
     */
    private function process(array $config): array
    {
        /** @var array{connections: array<string, array<string, mixed>>} $processed */
        $processed = (new Processor())->processConfiguration(new Configuration(), [$config]);

        return $processed;
    }
}
