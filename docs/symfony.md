**English** | [Русский](symfony.ru.md)

# Symfony bundle

`SwooleDoctrinePool\Bridge\Symfony\SwooleDoctrinePoolBundle` works on top of DoctrineBundle ^3 and Symfony ^8.1.

## What the bundle does

1. **The Extension** reads `swoole_doctrine_pool.connections`, registers `PoolRegistry` (one per worker),
   `PoolDriverMiddleware` tagged `doctrine.middleware` (`connection: <name>`, `priority: 1024`) for every
   enabled connection, the listeners and `PoolStatsProviderInterface`.
2. **`PoolConnectionPass`** finds `doctrine.dbal.<name>_connection` and injects into the connection parameters
   `driverClass` = `SwooleDoctrinePool\Driver\Driver`, `wrapperClass` = `CoroutineSafeConnection`
   (or your subclass) and `driverOptions['pool']` = the options from the configuration. Nothing to write
   in `doctrine.yaml`.
3. **`PoolDriverMiddleware`** is the innermost middleware (priority 1024 > DoctrineBundle's 10): it receives
   the real `pdo_pgsql` driver and wraps it into the pool. It is what guarantees the pool with `url:`.

### `url:` / `DATABASE_URL`

DoctrineBundle's `ConnectionFactory` **removes `driverClass`** from the parameters at runtime when `url:` has an
explicit scheme. That is why the pool is supplied by the middleware, not only by `driverClass`: with
`url: postgresql://…` everything works; with `url: mysql://…` the bundle fails with
`InvalidConfigurationException` — the pool is Postgres-only.

### Configuration errors (at container build time)

| Error | What to do |
|---|---|
| connection not found in `doctrine.dbal.connections` | a typo in the name; the message lists the known ones |
| `driver_class` already set | remove it — the bundle injects it |
| `wrapper_class` does not extend `CoroutineSafeConnection` | extend it or remove `wrapper_class` |
| pool options in `doctrine...options.pool` | move them to `swoole_doctrine_pool` |
| `replica`/`primary` | replicas are not supported in 3.0 |

## Recommended `doctrine.yaml`

```yaml
doctrine:
  dbal:
    server_version: '17'        # without it DoctrineBundle connects to the DB while building the platform
    idle_connection_ttl: 0      # IdleConnectionMiddleware closes connections on kernel.request — the pool does not need it
```

## Listeners

| Event | What it does | Priority |
|---|---|---|
| `kernel.terminate` | `PoolRegistry::releaseCurrentCoroutine()` — returns the connection right after the response. Under a coroutine-per-request runtime it runs in the request coroutine | −1024, after everybody |
| `console.terminate` | release + `closeAll()`: the process is about to exit, and a live maintenance timer would keep `Coroutine\run()` from returning | −1024 |
| `swoole.worker_start` | warm-up: opens `min_idle` connections for pools with `min_idle > 0` | |
| `swoole.worker_exit` | `PoolRegistry::drainAll()` — the worker is leaving (`reload_async`, `max_request`, memory restart) but still serves in-flight requests: timers stopped, idle closed, new leases still handed out. Fires repeatedly; idempotent | |
| `swoole.worker_stop`, `swoole.before_shutdown` | `PoolRegistry::closeAll()` — full shutdown; idempotent | |

The `swoole.*` events are string names your Swoole runtime is expected to dispatch; there is no dependency on
any runtime, and without them the listeners stay silent. Your own runtime → call `$registry->closeAll()` in `workerStop` yourself
(`PoolRegistry` is available as a service).

Safety net without listeners: a lease is released in `Coroutine::defer` when the coroutine ends.

## Logs

Monolog channel `swoole_pool`:

```yaml
monolog:
  channels: ['swoole_pool']
```

`warning` — a connection returned with an open transaction (a `ROLLBACK` was issued; this is a handler bug),
pools from the parent process discarded; `error` — a failed `ROLLBACK`/`DISCARD ALL` (the connection is
destroyed), an event listener exception.

## Verification

```bash
bin/console debug:config swoole_doctrine_pool          # effective values
bin/console debug:container database_connection        # the CoroutineSafeConnection class
```

## Boundaries

- The package makes **DBAL** coroutine-safe. `EntityManager`/UnitOfWork — one per coroutine — is the
  application's responsibility (see [use-cases.md, scenario 3](use-cases.md#3-orm-an-entitymanager-per-coroutine)).
- `PrimaryReadReplicaConnection` (`replica:`) is not supported.
