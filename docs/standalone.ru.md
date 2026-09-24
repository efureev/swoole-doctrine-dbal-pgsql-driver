[English](standalone.md) | **Русский**

# Без Symfony

```php
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use SwooleDoctrinePool\Driver\PoolDriverMiddleware;
use SwooleDoctrinePool\Pool\PoolRegistry;

// Один реестр на воркер. Логгер (PSR-3) и диспетчер (PSR-14) — по желанию.
$registry = new PoolRegistry(dispatcher: $dispatcher, logger: $logger);

$config = new Configuration();
// Пул должен быть первым (самым внутренним) middleware.
$config->setMiddlewares([new PoolDriverMiddleware($registry)]);

$connection = DriverManager::getConnection([
    'driverClass'   => Driver::class,
    'wrapperClass'  => CoroutineSafeConnection::class,   // обязателен
    'host' => '127.0.0.1', 'port' => 5432, 'dbname' => 'app', 'user' => 'app', 'password' => 'secret',
    'serverVersion' => '17',
    'driverOptions' => [
        'pool' => [
            'size' => 20, 'min_idle' => 2, 'acquire_timeout' => 3.0, 'max_lifetime' => 1800,
            'idle_timeout' => 60, 'max_uses' => 0, 'validate_idle_after' => 5.0,
            'reset_on_release' => 'discard', 'maintenance_interval' => 0,
        ],
        PDO::ATTR_TIMEOUT => 5,        // int-ключи доходят до PDO (для pgsql это connect_timeout)
    ],
], $config);
```

## Жизненный цикл воркера

```php
$server->set(['hook_flags' => SWOOLE_HOOK_ALL]);      // SWOOLE_HOOK_PDO_PGSQL обязателен

$server->on('workerStart', static function () use ($registry): void {
    $registry->warmUpAll();          // только уже созданные пулы; для прогрева до первого запроса —
                                     // (new Driver(registry: $registry))->poolFor($params)->warmUp()
});

$server->on('request', static function ($req, $res) use ($connection): void {
    try {
        // ... $connection->transactional(...)
    } finally {
        $connection->close();        // вернуть соединение сразу; без этого — в defer при конце корутины
    }
});

$server->on('workerStop', static fn() => $registry->closeAll());
```

`$connection->close()` в корутине освобождает только lease текущей корутины — обёртка остаётся рабочей.

## Что обязательно

- `wrapperClass` — `CoroutineSafeConnection` или её наследник. Без неё `Driver::connect()` бросает
  `InvalidConfigurationException`: стандартная обёртка хранит уровень транзакции в одном поле для всех корутин.
- `PoolDriverMiddleware` в `Configuration::setMiddlewares()`, если хотите общий `PoolRegistry` с логами и событиями.
  Без него `new Driver()` создаст собственный реестр — без логгера, но работающий.
- Хук `SWOOLE_HOOK_PDO_PGSQL` в HTTP-воркерах. Без него пул работает, но PDO блокирует процесс на время
  каждого запроса; warning пишется один раз на реестр. В консольной команде и в master (одна корутина)
  это безвредно.

## Вне корутины

`Coroutine::getCid() === -1` (обычный CLI, phpunit без `Co\run`) — драйвер держит одно прямое PDO без пула,
таймеров и `defer`. Транзакции работают, `close()` откатывает незавершённую и закрывает соединение.

Ограничение Swoole: если хук pdo_pgsql включён глобально (`Runtime::enableCoroutine()` в начале скрипта),
PDO вне корутины бросает `Swoole\Error: API must be called in the coroutine`. Для скриптов, которые
работают и снаружи, задавайте хуки через `Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])` — они
действуют только внутри `Coroutine\run()`.
