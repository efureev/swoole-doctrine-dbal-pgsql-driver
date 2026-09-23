# Миграция с 2.x на 3.0

Версия 3.0 — переписанный с нуля пакет. Совместимости с 2.x нет; шаги ниже — что сделать в приложении.

## 1. Платформа

| | 2.x | 3.0 |
|---|---|---|
| PHP | ≥ 8.2 | ≥ 8.5 |
| Swoole | 5.x с `Swoole\Coroutine\PostgreSQL` | ≥ 6.2, сборка с `--enable-swoole-pgsql`, `SWOOLE_HOOK_PDO_PGSQL` в `hook_flags` |
| doctrine/dbal | ^4.0 | ^4.4 |
| Symfony | ^7.1 \|\| ^8.0 | ^8.1 |
| DoctrineBundle | — | ^3 |
| PDO-драйвер `pgsql` | не требовался | корутинный из Swoole (`coroutine_pgsql => enabled`); штатный `ext-pdo_pgsql` допустим, если грузится раньше swoole |

Проверьте образ: `php -r 'var_dump(defined("SWOOLE_HOOK_PDO_PGSQL"), in_array("pgsql", PDO::getAvailableDrivers()));'`
— оба `true`. Официальные `phpswoole/swoole:6.2.x-php8.5[-zts]` подходят.

## 2. Пакет

```bash
composer require efureev/swoole-doctrine-dbal-pgsql-driver:^3.0
```

## 3. Namespace

`Swoole\Packages\Doctrine\DBAL\*` → `SwooleDoctrinePool\*`. Найти использования:

```bash
grep -rn 'Swoole\\Packages\\Doctrine' src config
```

## 4. Бандл

```php
// было
Swoole\Packages\Doctrine\DBAL\SwooleDoctrineDbalPoolBundle::class => ['all' => true],
// стало
SwooleDoctrinePool\Bridge\Symfony\SwooleDoctrinePoolBundle::class => ['all' => true],
```

## 5. Конфигурация

Было (`doctrine.yaml`):

```yaml
doctrine:
  dbal:
    connections:
      swoole:
        driver_class: 'Swoole\Packages\Doctrine\DBAL\PgSQL\Driver'
        options:
          poolSize: 10
          usedTimes: 5
          connectionTTL: 60
          tickFrequency: 60000
          connectionDelay: 2
          useConnectionPool: true
          retryMaxAttempts: 2
          retryDelay: 1000
```

Стало: `driver_class` и `options.*` убрать, добавить `config/packages/swoole_doctrine_pool.yaml`:

```yaml
swoole_doctrine_pool:
  connections:
    swoole:
      size: 10
      max_uses: 5
      idle_timeout: 60
      maintenance_interval: 60
      acquire_timeout: 2.0
```

| 2.x | 3.0 | Примечание |
|---|---|---|
| `poolSize` | `size` | |
| `usedTimes` | `max_uses` | 0 = без ограничения (в 2.x тоже) |
| `connectionTTL` | `idle_timeout` | секунды |
| `tickFrequency` (мс) | `maintenance_interval` (с) | 60000 → 60 |
| `connectionDelay` | `acquire_timeout` | float, секунды |
| `useConnectionPool: false` | `enabled: false` | |
| `retryMaxAttempts`, `retryDelay` | — | пул ждёт слот до `acquire_timeout` |
| — | `min_idle`, `max_lifetime`, `validate_idle_after`, `reset_on_release` | новые, см. [docs/configuration.md](docs/configuration.md) |

Имя соединения больше не обязано быть `swoole`. Обёртка `wrapper_class` подставляется автоматически;
если у вас своя — наследуйте `SwooleDoctrinePool\DBAL\CoroutineSafeConnection`.

Добавьте в `doctrine.yaml`: `server_version` и `idle_connection_ttl: 0`.

Схема «pooled-соединение для HTTP + `direct` для консоли/миграций» больше не нужна: вне корутины драйвер
сам работает в прямом режиме, под `ConsoleApplication` swoole-runtime-bundle — через пул.

## 6. Удалённый API

`Cursor`, `SQLParserUtils`, `DriverKeeperMiddleware`, `DriverMiddleware`, `ConnectionClosePass`,
`ConnectionCloseSubscriber`, `ConnectionPoolFactory`, `ConnectionPoolFactoryInterface`, `ConnectionPoolKeeper`,
`ConnectionPoolInterface`, `ConnectionPoolConfig`, `ConnectionStats`, `Scaler`, `ConnectionDirect`, `example/`.

Standalone-подключение: [docs/standalone.md](docs/standalone.md) (`PoolDriverMiddleware` + `PoolRegistry`).

## 7. События

| 2.x (`Swoole\Packages\Doctrine\DBAL\PgSQL\Events`) | 3.0 (`SwooleDoctrinePool\Event`) |
|---|---|
| `PoolCreated` | `PoolCreated` (`poolLabel`, `config`) |
| `PoolClosed` | `PoolClosed` (`poolLabel`, `stats`) |
| `PoolConnectionCreated` | `ConnectionOpened` |
| `PoolConnectionObtaining` | `LeaseAcquired` |
| `PoolConnectionPushToPool` | `LeaseReleased` |
| `PoolConnectionRemoved` | `ConnectionClosed` (с `reason`) |
| `PoolEvent` (строка) | — ; новые `RolledBackOnRelease`, `AcquireTimedOut` |

Полей `capacity`/`chanLength`/`chanStats` нет: используйте `PoolStats` (`AcquireTimedOut::stats`,
`PoolStatsProviderInterface`). События — PSR-14, подписка через `#[AsEventListener]` по классу.

## 8. Рантайм

- Под swoole-runtime-bundle ничего делать не нужно. Свой рантайм: `PoolRegistry::closeAll()` на `workerStop`.
- Соединение держится до `kernel.terminate` (или `close()`); `size` — предел конкурентных запросов
  к БД в воркере. Если в 2.x стояло `poolSize: 3` «на пробу», для боевой нагрузки поднимите.
- Дочерние корутины (`go()` в обработчике) — вне транзакции родителя, со своим соединением.
- Один `EntityManager` на несколько конкурентных корутин небезопасен и в 2.x, и в 3.0 — пакет это не чинит.

## 9. Проверка

```bash
bin/console debug:config swoole_doctrine_pool
bin/console debug:container database_connection     # SwooleDoctrinePool\DBAL\CoroutineSafeConnection
```

Под нагрузкой: `rollbacks_on_release_total` = 0, `acquire_timeouts_total` = 0, в `pg_stat_activity` нет
`idle in transaction` после ответов.

## Откат

`composer require efureev/swoole-doctrine-dbal-pgsql-driver:^2.1` и вернуть конфигурацию — но 2.x не работает
на Swoole 6.
