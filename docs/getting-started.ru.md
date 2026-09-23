[English](getting-started.md) | **Русский**

# Быстрый старт

## Требования

| | |
|---|---|
| PHP | ≥ 8.5 (ZTS — рабочая сборка под Swoole) |
| ext-swoole | ≥ 6.2, собранный с `--enable-swoole-pgsql`; `SWOOLE_HOOK_PDO_PGSQL` в `hook_flags` (входит в `SWOOLE_HOOK_ALL`) |
| PDO-драйвер `pgsql` | корутинный из Swoole (`php --ri swoole` → `coroutine_pgsql => enabled`). Штатный `ext-pdo_pgsql` может оставаться, но должен грузиться раньше swoole (приоритет ini), чтобы Swoole перерегистрировал драйвер |
| doctrine/dbal | ^4.4 |
| Symfony (опционально) | ^8.1 + doctrine/doctrine-bundle ^3 |

Проверить образ:

```bash
php -r 'var_dump(swoole_version(), defined("SWOOLE_HOOK_PDO_PGSQL"), in_array("pgsql", PDO::getAvailableDrivers()));'
```

Все три значения — `6.2.x`, `true`, `true`. Официальные `phpswoole/swoole:6.2.x-php8.5[-zts]` подходят.

## Установка

```bash
composer require efureev/swoole-doctrine-dbal-pgsql-driver
```

## Symfony

1. Зарегистрировать бандл:

   ```php
   // config/bundles.php
   SwooleDoctrinePool\Bridge\Symfony\SwooleDoctrinePoolBundle::class => ['all' => true],
   ```

2. Назвать соединения, которые идут через пул:

   ```yaml
   # config/packages/swoole_doctrine_pool.yaml
   swoole_doctrine_pool:
     connections:
       default: ~          # все настройки по умолчанию; см. configuration.md
   ```

3. В `doctrine.yaml` ничего про пул не писать; добавить две строки:

   ```yaml
   doctrine:
     dbal:
       url: '%env(resolve:DATABASE_URL)%'
       server_version: '17'          # иначе DoctrineBundle подключится к БД при сборке платформы
       idle_connection_ttl: 0        # простоем соединений управляет пул
   ```

4. Убедиться, что в настройках Swoole-сервера `hook_flags` включает `SWOOLE_HOOK_PDO_PGSQL`
   (для swoole-runtime-bundle — `settings.hook_flags` в `APP_RUNTIME_OPTIONS`).

5. Проверить:

   ```bash
   bin/console debug:config swoole_doctrine_pool
   bin/console debug:container database_connection     # SwooleDoctrinePool\DBAL\CoroutineSafeConnection
   ```

Под swoole-runtime-bundle остальное автоматически: соединение возвращается в пул на `kernel.terminate`,
пулы закрываются на `swoole.worker_stop`, прогреваются на `swoole.worker_start`. Свой рантайм —
[symfony.md](symfony.ru.md#слушатели).

## Без Symfony

```php
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use SwooleDoctrinePool\Driver\PoolDriverMiddleware;
use SwooleDoctrinePool\Pool\PoolRegistry;

$registry = new PoolRegistry(logger: $logger);           // один на воркер

$config = new Configuration();
$config->setMiddlewares([new PoolDriverMiddleware($registry)]);

$connection = DriverManager::getConnection([
    'driverClass'  => Driver::class,
    'wrapperClass' => CoroutineSafeConnection::class,
    'host' => '127.0.0.1', 'dbname' => 'app', 'user' => 'app', 'password' => 'secret',
    'serverVersion' => '17',
    'driverOptions' => ['pool' => ['size' => 20]],
], $config);

$server->set(['hook_flags' => SWOOLE_HOOK_ALL]);
$server->on('request', static function ($req, $res) use ($connection): void {
    try {
        $res->end(json_encode($connection->fetchAssociative('SELECT now() AS at')));
    } finally {
        $connection->close();                            // вернуть соединение сразу
    }
});
$server->on('workerStop', static fn() => $registry->closeAll());
```

Подробнее — [standalone.md](standalone.ru.md).

## Первая проверка под нагрузкой

```bash
ab -c 50 -n 2000 http://127.0.0.1:9501/
```

Затем в Postgres:

```sql
SELECT state, count(*) FROM pg_stat_activity WHERE application_name = 'app' GROUP BY 1;
```

Ожидание: не больше `size × workers` соединений, ни одного `idle in transaction` после прогона.
В статистике пула (`PoolStatsProviderInterface`) `acquire_timeouts_total = 0`, `rollbacks_on_release_total = 0`.

Дальше: [use-cases.md](use-cases.ru.md) — как это ведёт себя в типичных сценариях.
