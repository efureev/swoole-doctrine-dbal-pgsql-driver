<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Config;

use SwooleDoctrinePool\Exception\InvalidConfigurationException;

use function array_key_exists;
use function array_keys;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Настройки одного пула. Единственный источник значений по умолчанию — и для standalone-использования
 * (driverOptions['pool']), и для Symfony-конфигурации бандла.
 */
final readonly class PoolConfig
{
    /** Ключ внутри DBAL-параметра driverOptions, под которым лежат опции пула. */
    public const string OPTIONS_KEY = 'pool';

    public const int DEFAULT_SIZE = 10;
    public const int DEFAULT_MIN_IDLE = 0;
    public const float DEFAULT_ACQUIRE_TIMEOUT = 5.0;
    public const int DEFAULT_MAX_LIFETIME = 3600;
    public const int DEFAULT_IDLE_TIMEOUT = 300;
    public const int DEFAULT_MAX_USES = 0;
    public const float DEFAULT_VALIDATE_IDLE_AFTER = 5.0;
    public const string DEFAULT_RESET_ON_RELEASE = 'discard';
    public const int DEFAULT_MAINTENANCE_INTERVAL = 0;

    /** @var list<string> */
    public const array KEYS = [
        'size',
        'min_idle',
        'acquire_timeout',
        'max_lifetime',
        'idle_timeout',
        'max_uses',
        'validate_idle_after',
        'reset_on_release',
        'maintenance_interval',
    ];

    /**
     * @param ?float $maxLifetime       null — без ограничения
     * @param ?float $idleTimeout       null — никогда не закрывать по простою
     * @param ?float $validateIdleAfter   null — никогда не проверять; 0.0 — проверять всегда
     * @param float  $maintenanceInterval 0 — без таймера: простаивающие закрываются лениво при возврате соединений
     */
    public function __construct(
        public int $size = self::DEFAULT_SIZE,
        public int $minIdle = self::DEFAULT_MIN_IDLE,
        public float $acquireTimeout = self::DEFAULT_ACQUIRE_TIMEOUT,
        public ?float $maxLifetime = self::DEFAULT_MAX_LIFETIME,
        public ?float $idleTimeout = self::DEFAULT_IDLE_TIMEOUT,
        public int $maxUses = self::DEFAULT_MAX_USES,
        public ?float $validateIdleAfter = self::DEFAULT_VALIDATE_IDLE_AFTER,
        public ResetPolicy $resetOnRelease = ResetPolicy::Discard,
        public float $maintenanceInterval = self::DEFAULT_MAINTENANCE_INTERVAL,
    ) {
        if ($size < 1) {
            throw InvalidConfigurationException::constraint(sprintf('size должен быть >= 1, получено %d.', $size));
        }

        if ($minIdle < 0 || $minIdle > $size) {
            throw InvalidConfigurationException::constraint(sprintf(
                'min_idle должен быть в диапазоне 0..size (%d), получено %d.',
                $size,
                $minIdle,
            ));
        }

        if ($acquireTimeout <= 0.0) {
            throw InvalidConfigurationException::constraint(sprintf(
                'acquire_timeout должен быть > 0, получено %s.',
                $acquireTimeout,
            ));
        }

        if ($maxLifetime !== null && $maxLifetime <= 0.0) {
            throw InvalidConfigurationException::constraint(
                'max_lifetime должен быть > 0 или null/0 (без ограничения).'
            );
        }

        if ($idleTimeout !== null && $idleTimeout <= 0.0) {
            throw InvalidConfigurationException::constraint('idle_timeout должен быть > 0 или null/0 (никогда).');
        }

        if ($maxUses < 0) {
            throw InvalidConfigurationException::constraint('max_uses должен быть >= 0.');
        }

        if ($validateIdleAfter !== null && $validateIdleAfter < 0.0) {
            throw InvalidConfigurationException::constraint('validate_idle_after должен быть >= 0 или null.');
        }

        if ($maintenanceInterval < 0.0) {
            throw InvalidConfigurationException::constraint('maintenance_interval должен быть >= 0 (0 — без таймера).');
        }
    }

    /**
     * Читает опции из driverOptions['pool'] DBAL-параметров. Отсутствие ключа — все значения по умолчанию.
     *
     * @param array<string, mixed> $params
     */
    public static function fromDbalParams(array $params): self
    {
        $driverOptions = $params['driverOptions'] ?? [];
        $options = is_array($driverOptions) ? ($driverOptions[self::OPTIONS_KEY] ?? []) : [];

        if (!is_array($options)) {
            throw InvalidConfigurationException::invalidValue(self::OPTIONS_KEY, $options, 'массив опций пула');
        }

        return self::fromArray($options);
    }

    /**
     * Значения могут приходить строками из env: "10", "5.5", "" / "null" / "~" для null.
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        foreach (array_keys($options) as $key) {
            if (!in_array((string)$key, self::KEYS, true)) {
                throw InvalidConfigurationException::unknownKey((string)$key, self::KEYS);
            }
        }

        $reset = array_key_exists('reset_on_release', $options)
            ? self::resetPolicy($options['reset_on_release'])
            : ResetPolicy::Discard;

        return new self(
            size: self::int($options, 'size', self::DEFAULT_SIZE),
            minIdle: self::int($options, 'min_idle', self::DEFAULT_MIN_IDLE),
            acquireTimeout: self::float($options, 'acquire_timeout', self::DEFAULT_ACQUIRE_TIMEOUT),
            maxLifetime: self::nullableSeconds($options, 'max_lifetime', self::DEFAULT_MAX_LIFETIME, zeroIsNull: true),
            idleTimeout: self::nullableSeconds($options, 'idle_timeout', self::DEFAULT_IDLE_TIMEOUT, zeroIsNull: true),
            maxUses: self::int($options, 'max_uses', self::DEFAULT_MAX_USES),
            validateIdleAfter: self::nullableSeconds(
                $options,
                'validate_idle_after',
                self::DEFAULT_VALIDATE_IDLE_AFTER,
                zeroIsNull: false,
            ),
            resetOnRelease: $reset,
            maintenanceInterval: self::float($options, 'maintenance_interval', self::DEFAULT_MAINTENANCE_INTERVAL),
        );
    }

    /** @return array<string, int|float|string|null> */
    public function toArray(): array
    {
        return [
            'size' => $this->size,
            'min_idle' => $this->minIdle,
            'acquire_timeout' => $this->acquireTimeout,
            'max_lifetime' => $this->maxLifetime,
            'idle_timeout' => $this->idleTimeout,
            'max_uses' => $this->maxUses,
            'validate_idle_after' => $this->validateIdleAfter,
            'reset_on_release' => $this->resetOnRelease->value,
            'maintenance_interval' => $this->maintenanceInterval,
        ];
    }

    /** @param array<string, mixed> $options */
    private static function int(array $options, string $key, int $default): int
    {
        if (!array_key_exists($key, $options) || self::isNullish($options[$key])) {
            return $default;
        }

        $value = $options[$key];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (string)(int)$value === trim($value)) {
            return (int)$value;
        }

        if (is_float($value) && (float)(int)$value === $value) {
            return (int)$value;
        }

        throw InvalidConfigurationException::invalidValue($key, $value, 'целое число');
    }

    /** @param array<string, mixed> $options */
    private static function float(array $options, string $key, float $default): float
    {
        if (!array_key_exists($key, $options) || self::isNullish($options[$key])) {
            return $default;
        }

        $value = $options[$key];

        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float)$value;
        }

        throw InvalidConfigurationException::invalidValue($key, $value, 'число');
    }

    /** @param array<string, mixed> $options */
    private static function nullableSeconds(array $options, string $key, float $default, bool $zeroIsNull): ?float
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }

        $value = $options[$key];

        if (self::isNullish($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $seconds = (float)$value;
        } elseif (is_string($value) && is_numeric($value)) {
            $seconds = (float)$value;
        } else {
            throw InvalidConfigurationException::invalidValue($key, $value, 'число секунд или null');
        }

        return $zeroIsNull && $seconds === 0.0 ? null : $seconds;
    }

    private static function resetPolicy(mixed $value): ResetPolicy
    {
        if ($value instanceof ResetPolicy) {
            return $value;
        }

        if (self::isNullish($value)) {
            return ResetPolicy::Discard;
        }

        if (is_string($value)) {
            $policy = ResetPolicy::tryFrom(strtolower(trim($value)));

            if ($policy !== null) {
                return $policy;
            }
        }

        throw InvalidConfigurationException::invalidValue('reset_on_release', $value, '"discard" или "rollback_only"');
    }

    private static function isNullish(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return false;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['', 'null', '~'], true);
    }
}
