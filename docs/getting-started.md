**English** | [Русский](getting-started.ru.md)

# Getting started

## Requirements

| | |
|---|---|
| PHP | ≥ 8.5 (ZTS is the production build for Swoole) |
| ext-swoole | ≥ 6.2 built with `--enable-swoole-pgsql`; `SWOOLE_HOOK_PDO_PGSQL` in `hook_flags` (part of `SWOOLE_HOOK_ALL`) |
| PDO `pgsql` driver | Swoole's coroutine one (`php --ri swoole` → `coroutine_pgsql => enabled`). The stock `ext-pdo_pgsql` may stay loaded, but it must load before swoole (ini priority) so that Swoole re-registers the driver |
| doctrine/dbal | ^4.4 |
| Symfony (optional) | ^8.1 + doctrine/doctrine-bundle ^3 |

Check the image:

```bash
php -r 'var_dump(swoole_version(), defined("SWOOLE_HOOK_PDO_PGSQL"), in_array("pgsql", PDO::getAvailableDrivers()));'
```

Expected: `6.2.x`, `true`, `true`. The official `phpswoole/swoole:6.2.x-php8.5[-zts]` images qualify.

## Installation

```bash
composer require efureev/swoole-doctrine-dbal-pgsql-driver
```

## Symfony

1. Register the bundle:

   ```php
   // config/bundles.php
   SwooleDoctrinePool\Bridge\Symfony\SwooleDoctrinePoolBundle::class => ['all' => true],
   ```

2. Name the connections that go through the pool:

   ```yaml
   # config/packages/swoole_doctrine_pool.yaml
   swoole_doctrine_pool:
     connections:
       default: ~          # every setting at its default; see configuration.md
   ```

3. Write nothing about the pool in `doctrine.yaml`; add two lines:

   ```yaml
   doctrine:
     dbal:
       url: '%env(resolve:DATABASE_URL)%'
       server_version: '17'          # otherwise DoctrineBundle connects to the DB while building the platform
       idle_connection_ttl: 0        # idle handling belongs to the pool
   ```

4. Make sure the Swoole server's `hook_flags` include `SWOOLE_HOOK_PDO_PGSQL`
   (with a Symfony Runtime based server — usually `hook_flags` in `APP_RUNTIME_OPTIONS`).

5. Verify:

   ```bash
   bin/console debug:config swoole_doctrine_pool
   bin/console debug:container database_connection     # SwooleDoctrinePool\DBAL\CoroutineSafeConnection
   ```

If your runtime dispatches the `swoole.*` events, the rest is automatic: the connection returns to the pool on `kernel.terminate`,
pools close on `swoole.worker_stop` and warm up on `swoole.worker_start`. For your own runtime see
[symfony.md](symfony.md#listeners).

## Without Symfony

```php
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use SwooleDoctrinePool\Driver\PoolDriverMiddleware;
use SwooleDoctrinePool\Pool\PoolRegistry;

$registry = new PoolRegistry(logger: $logger);           // one per worker

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
        $connection->close();                            // return the connection right away
    }
});
$server->on('workerStop', static fn() => $registry->closeAll());
```

Details: [standalone.md](standalone.md).

## First check under load

```bash
ab -c 50 -n 2000 http://127.0.0.1:9501/
```

Then in Postgres:

```sql
SELECT state, count(*) FROM pg_stat_activity WHERE application_name = 'app' GROUP BY 1;
```

Expected: no more than `size × workers` connections, not a single `idle in transaction` after the run.
In the pool statistics (`PoolStatsProviderInterface`) `acquire_timeouts_total = 0` and
`rollbacks_on_release_total = 0`.

Next: [use-cases.md](use-cases.md) — how this behaves in typical scenarios.
