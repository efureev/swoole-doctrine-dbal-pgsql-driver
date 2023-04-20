<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

final class ConnectionPoolConfig
{
    public int $poolSize = 5;
    public int $usedTimes = 0;
    public int $connectionTTL = 60;
    public int $connectionDelay = 10;
    public bool $useConnectionPool = true;
    public int $tickFrequency = 60_000;

    public int $retryMaxAttempts = 2;
    public int $retryDelay = 1000;

    public function __construct(public array $config)
    {
        $this->applyConfig($config);
    }

    private function applyConfig(array $config): void
    {
        foreach ($config['driverOptions'] ?? [] as $prop => $value) {
            if (property_exists($this, $prop)) {
                $this->$prop = $value;
            }
        }
    }

    public function toArray(): array
    {
        return $this->config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }
}
