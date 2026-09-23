<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Bridge\Symfony\DependencyInjection;

use Override;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\Config\ResetPolicy;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_map;
use function is_int;
use function is_numeric;
use function is_string;

final class Configuration implements ConfigurationInterface
{
    public const string ALIAS = 'swoole_doctrine_pool';

    #[Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);

        $treeBuilder->getRootNode()
            ->children()
                ->append($this->connectionsNode());

        return $treeBuilder;
    }

    /**
     * Только min()/max()/enum на скалярах: пользовательский validate() на числовом узле получает
     * подставное значение вместо %env(...)% и отвергает корректную конфигурацию.
     */
    private function connectionsNode(): NodeDefinition
    {
        $node = (new TreeBuilder('connections'))->getRootNode();

        $node
            ->info(
                'Имена DBAL-соединений DoctrineBundle (doctrine.dbal.connections.*), которые переводятся на пул. '
                . 'Ключ — имя соединения; значение ~ даёт все настройки по умолчанию.'
            )
            ->useAttributeAsKey('name')
            ->normalizeKeys(false)
            ->arrayPrototype()
                ->info(
                    'Соединение переводится на пул: driver_class, wrapper_class и опции пула подставляются '
                    . 'автоматически, в doctrine.yaml их писать не нужно. enabled: false оставляет соединение '
                    . 'на обычном pdo_pgsql.'
                )
                ->canBeDisabled()
                ->children()
                    ->integerNode('size')
                        ->info(
                            'Максимум одновременно открытых соединений пула в одном воркере. Умножьте на число '
                            . 'воркеров и сравните с max_connections Postgres.'
                        )
                        ->min(1)
                        ->defaultValue(PoolConfig::DEFAULT_SIZE)
                    ->end()
                    ->integerNode('min_idle')
                        ->info(
                            'Сколько простаивающих соединений держать открытыми; 0 — открывать по требованию. '
                            . 'Не больше size.'
                        )
                        ->min(0)
                        ->defaultValue(PoolConfig::DEFAULT_MIN_IDLE)
                    ->end()
                    ->floatNode('acquire_timeout')
                        ->info(
                            'Секунды ожидания свободного соединения, когда все size заняты; по истечении — '
                            . 'PoolExhaustedException (наследует ConnectionException).'
                        )
                        ->min(0.001)
                        ->defaultValue(PoolConfig::DEFAULT_ACQUIRE_TIMEOUT)
                    ->end()
                    ->integerNode('max_lifetime')
                        ->info(
                            'Секунды жизни физического соединения: старше — закрывается при возврате в пул '
                            . 'или обслуживанием. 0 — без ограничения.'
                        )
                        ->min(0)
                        ->defaultValue(PoolConfig::DEFAULT_MAX_LIFETIME)
                    ->end()
                    ->integerNode('idle_timeout')
                        ->info(
                            'Секунды простоя, после которых соединение сверх min_idle закрывается обслуживанием. '
                            . '0 — никогда.'
                        )
                        ->min(0)
                        ->defaultValue(PoolConfig::DEFAULT_IDLE_TIMEOUT)
                    ->end()
                    ->integerNode('max_uses')
                        ->info(
                            'Сколько раз соединение выдаётся из пула до принудительного закрытия. '
                            . '0 — без ограничения.'
                        )
                        ->min(0)
                        ->defaultValue(PoolConfig::DEFAULT_MAX_USES)
                    ->end()
                    ->scalarNode('validate_idle_after')
                        ->info(
                            'Секунды простоя, после которых соединение перед выдачей проверяется (SELECT 1). '
                            . '0 — проверять всегда, ~ — никогда.'
                        )
                        ->defaultValue(PoolConfig::DEFAULT_VALIDATE_IDLE_AFTER)
                        ->validate()
                            ->ifTrue(static fn(mixed $value): bool => !self::isNullableSeconds($value))
                            ->thenInvalid('validate_idle_after: ожидается число секунд >= 0 или ~, получено %s.')
                        ->end()
                    ->end()
                    ->enumNode('reset_on_release')
                        ->info(
                            'Что делать с соединением при возврате: discard — DISCARD ALL (сброс сессии целиком), '
                            . 'rollback_only — только откат незавершённой транзакции. Откат делается всегда.'
                        )
                        ->values(array_map(
                            static fn(ResetPolicy $policy): string => $policy->value,
                            ResetPolicy::cases(),
                        ))
                        ->defaultValue(PoolConfig::DEFAULT_RESET_ON_RELEASE)
                    ->end()
                    ->integerNode('maintenance_interval')
                        ->info(
                            'Период таймера обслуживания в секундах: закрытие по idle_timeout/max_lifetime и '
                            . 'поддержание min_idle в тишине. 0 — без таймера: простаивающие закрываются лениво '
                            . 'при возврате соединений (живой таймер мешает завершению команд и демонов).'
                        )
                        ->min(0)
                        ->defaultValue(PoolConfig::DEFAULT_MAINTENANCE_INTERVAL)
                    ->end()
                ->end()
                ->validate()
                    ->ifTrue(static fn(array $c): bool => self::minIdleExceedsSize($c))
                    ->thenInvalid('min_idle не может превышать size: %s.')
                ->end();

        return $node;
    }

    /** is_int-guard: при env-плейсхолдере значения ещё строки, сравнивать их нельзя. */
    private static function minIdleExceedsSize(array $connection): bool
    {
        $minIdle = $connection['min_idle'] ?? 0;
        $size = $connection['size'] ?? 0;

        return is_int($minIdle) && is_int($size) && $minIdle > $size;
    }

    /** Строки без числа пропускаются: это env-плейсхолдеры, их разберёт PoolConfig в рантайме. */
    private static function isNullableSeconds(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return $value >= 0;
        }

        if (!is_string($value)) {
            return false;
        }

        return !is_numeric($value) || (float)$value >= 0.0;
    }
}
