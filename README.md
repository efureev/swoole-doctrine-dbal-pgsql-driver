**English** | [Русский](README.ru.md)

# swoole-doctrine-dbal-pgsql-driver

A PostgreSQL connection pool for Doctrine DBAL on Swoole: one physical connection per coroutine,
a coroutine-safe `Doctrine\DBAL\Connection` wrapper, and a Symfony bundle that moves DoctrineBundle
connections onto the pool with three lines of configuration.

| What you get | How |
|---|---|
| A bounded number of connections per worker at any concurrency | a pool with a semaphore on `Swoole\Coroutine\Channel`: a connection is leased lazily on the coroutine's first query and returned on `kernel.terminate` / `Coroutine::defer` |
| Transactions invisible to neighbouring coroutines | `CoroutineSafeConnection`: nesting level, rollback-only flag and isolation live on the coroutine's lease, not in the wrapper's shared field |
| Nothing lost, nothing leaked | on release: always `ROLLBACK` an unfinished transaction, then `DISCARD ALL`; lost connections are destroyed, a `Statement` from another coroutine is rejected |
| Real DBAL exceptions | the transport is PDO `pgsql` under the Swoole hook: SQLSTATE, `UniqueConstraintViolationException`, `DeadlockException`, `ConnectionLost` work exactly as without a pool |

## Requirements

| | |
|---|---|
| PHP | ≥ 8.5 (ZTS is the production build for Swoole) |
| ext-swoole | ≥ 6.2 built with `--enable-swoole-pgsql`; `SWOOLE_HOOK_PDO_PGSQL` in `hook_flags` (part of `SWOOLE_HOOK_ALL`) |
| PDO `pgsql` driver | Swoole's coroutine one (`php --ri swoole` → `coroutine_pgsql => enabled`). The stock `ext-pdo_pgsql` may stay loaded — it must load **before** swoole so that Swoole re-registers the driver |
| doctrine/dbal | ^4.4 |
| Symfony (optional) | ^8.1 with doctrine/doctrine-bundle ^3 |

The official `phpswoole/swoole:6.2.x-php8.5[-zts]` images are built with pgsql support.

## Installation

```bash
composer require efureev/swoole-doctrine-dbal-pgsql-driver
```

## Symfony: quick start

```php
// config/bundles.php
SwooleDoctrinePool\Bridge\Symfony\SwooleDoctrinePoolBundle::class => ['all' => true],
```

```yaml
# config/packages/swoole_doctrine_pool.yaml
swoole_doctrine_pool:
  connections:
    default: ~          # every pool setting at its default

# config/packages/doctrine.yaml — no driver_class / wrapper_class needed, the bundle injects them
doctrine:
  dbal:
    url: '%env(resolve:DATABASE_URL)%'
    server_version: '17'          # otherwise DoctrineBundle connects to the DB while building the platform
    idle_connection_ttl: 0        # idle handling belongs to the pool
```

Verify: `bin/console debug:config swoole_doctrine_pool` prints the defaults,
`bin/console debug:container database_connection` shows `CoroutineSafeConnection`.

Under [swoole-runtime-bundle](../swoole-runtime-bundle) the rest is automatic: the connection goes back
to the pool on `kernel.terminate`, pools are closed on `swoole.worker_stop`. Without it —
see [docs/symfony.md](docs/symfony.md).

## Without Symfony

```php
$registry = new PoolRegistry(logger: $logger);           // one per worker

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

Details: [docs/standalone.md](docs/standalone.md).

## Things to remember

- A lease lives until the end of the request (`kernel.terminate`, an explicit `$connection->close()`,
  or the end of the coroutine), not until the end of an SQL statement — that is what keeps `lastInsertId()`
  and transactions on one session. Consequently `size` is also the cap on concurrent DB requests per worker.
- A child coroutine (`go()` inside a handler) gets its **own** connection and is **outside** the parent's
  transaction.
- Do not touch the database before the worker starts: a pool inherited across fork is discarded with a warning.
- `size × number of workers` must fit into Postgres `max_connections`.
- The package makes DBAL coroutine-safe. The ORM UnitOfWork is still the state of one `EntityManager`:
  concurrent coroutines need an EntityManager per coroutine.
- `reset_on_release: rollback_only` saves a round-trip, but `SET`, advisory locks and temp tables survive
  the request. The default is `discard`.

## Documentation

| Document | Contents |
|---|---|
| [docs/index.md](docs/index.md) | table of contents |
| [docs/getting-started.md](docs/getting-started.md) | requirements, installation, wiring, first load check |
| [docs/use-cases.md](docs/use-cases.md) | 15 scenarios: HTTP handlers, transactions, ORM, parallel queries, daemons, console, multiple databases, shutdown, warm-up, failover, metrics, large results, session state, tests, limits |
| [docs/configuration.md](docs/configuration.md) | every `swoole_doctrine_pool.*` key and what it means; sizing for high load |
| [docs/symfony.md](docs/symfony.md) | what the bundle does, listeners, `url:`/`DATABASE_URL`, monolog |
| [docs/standalone.md](docs/standalone.md) | usage without Symfony |
| [docs/architecture.md](docs/architecture.md) | components, lease, acquire/release, data-safety guarantees |
| [docs/events.md](docs/events.md) | PSR-14 events |
| [docs/stats.md](docs/stats.md) | `PoolStats`, metrics hook, alerts |
| [docs/testing.md](docs/testing.md) | testing an application with the pool; the package's own tests |
| [docs/troubleshooting.md](docs/troubleshooting.md) | symptom → cause |
| [UPGRADE-3.0.md](UPGRADE-3.0.md) | migration from v2 (Russian) |
| [CHANGELOG.md](CHANGELOG.md) | change history (Russian) |
| [tests/Integration/README.md](tests/Integration/README.md) | how the integration tests run |

## Development

```bash
composer install --ignore-platform-req=ext-swoole   # locally without Swoole: phpcs, psalm, unit
composer ci                                          # phpcs + psalm + unit
composer up && composer integration                  # integration tests in docker (Postgres 17)
composer down
```

Integration image matrix: `phpswoole/swoole:6.2.3-php8.5-zts` (default),
`SWOOLE_IMAGES="phpswoole/swoole:6.2.3-php8.5" composer integration` — NTS.

## License

MIT
