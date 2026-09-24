**English** | [Русский](standalone.ru.md)

# Without Symfony

```php
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use SwooleDoctrinePool\Driver\PoolDriverMiddleware;
use SwooleDoctrinePool\Pool\PoolRegistry;

// One registry per worker. Logger (PSR-3) and dispatcher (PSR-14) are optional.
$registry = new PoolRegistry(dispatcher: $dispatcher, logger: $logger);

$config = new Configuration();
// The pool must be the first (innermost) middleware.
$config->setMiddlewares([new PoolDriverMiddleware($registry)]);

$connection = DriverManager::getConnection([
    'driverClass'   => Driver::class,
    'wrapperClass'  => CoroutineSafeConnection::class,   // mandatory
    'host' => '127.0.0.1', 'port' => 5432, 'dbname' => 'app', 'user' => 'app', 'password' => 'secret',
    'serverVersion' => '17',
    'driverOptions' => [
        'pool' => [
            'size' => 20, 'min_idle' => 2, 'acquire_timeout' => 3.0, 'max_lifetime' => 1800,
            'idle_timeout' => 60, 'max_uses' => 0, 'validate_idle_after' => 5.0,
            'reset_on_release' => 'discard', 'maintenance_interval' => 0,
        ],
        PDO::ATTR_TIMEOUT => 5,        // integer keys reach PDO (for pgsql this is connect_timeout)
    ],
], $config);
```

## Worker lifecycle

```php
$server->set(['hook_flags' => SWOOLE_HOOK_ALL]);      // SWOOLE_HOOK_PDO_PGSQL is mandatory

$server->on('workerStart', static function () use ($registry): void {
    $registry->warmUpAll();          // only pools that already exist; to warm up before the first request —
                                     // (new Driver(registry: $registry))->poolFor($params)->warmUp()
});

$server->on('request', static function ($req, $res) use ($connection): void {
    try {
        // ... $connection->transactional(...)
    } finally {
        $connection->close();        // return the connection now; otherwise defer does it when the coroutine ends
    }
});

$server->on('workerStop', static fn() => $registry->closeAll());
```

`$connection->close()` inside a coroutine releases only the current coroutine's lease — the wrapper stays usable.

## What is mandatory

- `wrapperClass` — `CoroutineSafeConnection` or a subclass. Without it `Driver::connect()` throws
  `InvalidConfigurationException`: the standard wrapper keeps the transaction level in one field shared by
  all coroutines.
- `PoolDriverMiddleware` in `Configuration::setMiddlewares()` if you want a shared `PoolRegistry` with logs and
  events. Without it `new Driver()` creates its own registry — without a logger, but working.
- The `SWOOLE_HOOK_PDO_PGSQL` hook in HTTP workers. Without it the pool still works, but PDO blocks the
  process for the duration of every query; a warning is logged once per registry. In a console command or in
  the master (one coroutine) that is harmless.

## Outside a coroutine

`Coroutine::getCid() === -1` (plain CLI, phpunit without `Co\run`) — the driver holds one direct PDO without
a pool, timers or `defer`. Transactions work; `close()` rolls back an unfinished one and closes the connection.

A Swoole limitation: if the pdo_pgsql hook is enabled globally (`Runtime::enableCoroutine()` at the top of the
script), PDO outside a coroutine throws `Swoole\Error: API must be called in the coroutine`. For scripts that
also run outside, set the hooks via `Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])` — they then apply only
inside `Coroutine\run()`.
