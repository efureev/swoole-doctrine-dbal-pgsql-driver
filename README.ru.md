[English](README.md) | **Русский**

# swoole-doctrine-dbal-pgsql-driver

Пул PostgreSQL-соединений для Doctrine DBAL под Swoole: одно физическое соединение на корутину,
coroutine-safe обёртка `Doctrine\DBAL\Connection` и Symfony-бандл, который переводит соединения
DoctrineBundle на пул тремя строками конфигурации.

| Что даёт                                                         | Как                                                                                                                                                            |
|------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Ограниченное число соединений на воркер при любой конкурентности | пул с семафором на `Swoole\Coroutine\Channel`: соединение берётся лениво при первом запросе корутины и возвращается по `kernel.terminate` / `Coroutine::defer` |
| Транзакции, не видимые соседним корутинам                        | `CoroutineSafeConnection`: уровень вложенности, rollback-only и изоляция живут на lease корутины, а не в общем поле обёртки                                    |
| Ничего не теряется и не «дотекает»                               | при возврате: всегда `ROLLBACK` незавершённой транзакции, затем `DISCARD ALL`; потерянные соединения уничтожаются, `Statement` чужой корутины отвергается      |
| Полноценные ошибки DBAL                                          | транспорт — штатный `pdo_pgsql` под хуком Swoole: SQLSTATE, `UniqueConstraintViolationException`, `DeadlockException`, `ConnectionLost` работают как без пула  |

## Требования

|                       |                                                                                                                             |
|-----------------------|-----------------------------------------------------------------------------------------------------------------------------|
| PHP                   | ≥ 8.5 (ZTS — рабочая сборка под Swoole)                                                                                     |
| ext-swoole            | ≥ 6.2, собранный с `--enable-swoole-pgsql`; в `hook_flags` должен быть `SWOOLE_HOOK_PDO_PGSQL` (входит в `SWOOLE_HOOK_ALL`) |
| PDO-драйвер `pgsql` | корутинный из Swoole (`php --ri swoole` → `coroutine_pgsql => enabled`). Штатный `ext-pdo_pgsql` может оставаться — он должен грузиться **раньше** swoole, чтобы Swoole перерегистрировал драйвер |
| doctrine/dbal         | ^4.4                                                                                                                        |
| Symfony (опционально) | ^8.1, doctrine/doctrine-bundle ^3                                                                                           |

Официальные образы `phpswoole/swoole:6.2.x-php8.5[-zts]` собраны с поддержкой pgsql.

## Установка

```bash
composer require efureev/swoole-doctrine-dbal-pgsql-driver
```

## Symfony: быстрый старт

```php
// config/bundles.php
SwooleDoctrinePool\Bridge\Symfony\SwooleDoctrinePoolBundle::class => ['all' => true],
```

```yaml
# config/packages/swoole_doctrine_pool.yaml
swoole_doctrine_pool:
  connections:
    default: ~          # все настройки пула по умолчанию

# config/packages/doctrine.yaml — driver_class и wrapper_class писать не нужно, бандл подставит сам
doctrine:
  dbal:
    url: '%env(resolve:DATABASE_URL)%'
    server_version: '17'          # иначе DoctrineBundle подключится к БД при сборке платформы
    idle_connection_ttl: 0        # простоем соединений управляет пул
```

Проверка: `bin/console debug:config swoole_doctrine_pool` показывает значения по умолчанию,
`bin/console debug:container database_connection` — класс `CoroutineSafeConnection`.

Под [swoole-runtime-bundle](../swoole-runtime-bundle) остальное происходит само: соединение
возвращается в пул на `kernel.terminate`, пулы закрываются на `swoole.worker_stop`. Без него —
см. [docs/symfony.md](docs/symfony.ru.md).

## Без Symfony

```php
$registry = new PoolRegistry(logger: $logger);           // один на воркер

$config = new Configuration();
$config->setMiddlewares([new PoolDriverMiddleware($registry)]);

$connection = DriverManager::getConnection([
    'driverClass'  => SwooleDoctrinePool\Driver\Driver::class,
    'wrapperClass' => SwooleDoctrinePool\DBAL\CoroutineSafeConnection::class,
    'host' => '127.0.0.1', 'dbname' => 'app', 'user' => 'app', 'password' => 'secret',
    'serverVersion' => '17',
    'driverOptions' => ['pool' => ['size' => 20]],
], $config);

$server->on('workerStop', static fn() => $registry->closeAll());
```

Подробнее — [docs/standalone.md](docs/standalone.ru.md).

## Что важно помнить

- Lease живёт до конца запроса (`kernel.terminate`, явный `$connection->close()` или конец корутины),
  а не до конца запроса SQL: так `lastInsertId()` и транзакции остаются на одной сессии. Соответственно
  `size` — это и предел конкурентных запросов к БД в воркере.
- Дочерняя корутина (`go()` внутри обработчика) получает **своё** соединение и находится **вне**
  транзакции родителя.
- Не обращайтесь к БД до старта воркера: пул, унаследованный после fork, будет отброшен с warning.
- `size × число воркеров` должно укладываться в `max_connections` Postgres.
- Пакет делает coroutine-safe только DBAL. UnitOfWork ORM остаётся состоянием одного `EntityManager`:
  под конкурентные корутины нужен свой EntityManager на корутину.
- `reset_on_release: rollback_only` экономит round-trip, но `SET`, advisory locks и temp-таблицы
  переживут запрос. По умолчанию — `discard`.

## Документация

| Документ                                                   | Что внутри                                                       |
|------------------------------------------------------------|------------------------------------------------------------------|
| [docs/index.md](docs/index.ru.md)                          | оглавление                                                       |
| [docs/configuration.md](docs/configuration.ru.md)          | все ключи `swoole_doctrine_pool.*` и их смысл; подбор под хайлоад |
| [docs/symfony.md](docs/symfony.ru.md)                      | что делает бандл, слушатели, `url:`/`DATABASE_URL`, monolog      |
| [docs/standalone.md](docs/standalone.ru.md)                | использование без Symfony                                        |
| [docs/architecture.md](docs/architecture.ru.md)            | компоненты, lease, acquire/release, гарантии безопасности данных |
| [docs/events.md](docs/events.ru.md)                        | события PSR-14                                                   |
| [docs/stats.md](docs/stats.ru.md)                          | `PoolStats` и точка подключения метрик                           |
| [docs/troubleshooting.md](docs/troubleshooting.ru.md)      | симптом → причина                                                |
| [UPGRADE-3.0.md](UPGRADE-3.0.md)                           | миграция с v2                                                    |
| [CHANGELOG.md](CHANGELOG.md)                               | история изменений                                                |
| [tests/Integration/README.md](tests/Integration/README.md) | как гоняются интеграционные тесты                                |

## Разработка

```bash
composer install --ignore-platform-req=ext-swoole   # локально без Swoole: phpcs, psalm, unit
composer ci                                          # phpcs + psalm + unit
composer up && composer integration                  # интеграционные тесты в docker (Postgres 17)
composer down
```

Матрица интеграционных образов: `phpswoole/swoole:6.2.3-php8.5-zts` (по умолчанию),
`SWOOLE_IMAGES="phpswoole/swoole:6.2.3-php8.5" composer integration` — NTS.

## Лицензия

MIT
