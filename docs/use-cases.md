**English** | [Русский](use-cases.ru.md)

# Use cases

Each scenario: what happens to the connection, what to do in code, what to watch.
Terms: a *lease* is a connection handed to a coroutine until release; *release* is the return to the pool.

## 1. HTTP handler under a Swoole runtime

Nothing to do. A controller uses `Doctrine\DBAL\Connection` / `EntityManagerInterface` as usual:

```php
final class OrderController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/orders/{id}')]
    public function show(int $id): JsonResponse
    {
        $order = $this->db->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$id]);

        return new JsonResponse($order ?: null, $order ? 200 : 404);
    }
}
```

Under the hood: the first `fetchAssociative` in the request's coroutine takes a lease from the pool; every
later query of this HTTP request goes over it; `kernel.terminate` (after the response is sent) returns the
connection; if the handler died before terminate, `Coroutine::defer` returns it when the coroutine ends.

Watch: `PoolStats::inUse` under load ≈ number of in-flight requests, `acquireTimeoutsTotal` = 0.

## 2. Transaction

```php
$this->db->transactional(function (Connection $db) use ($from, $to, $amount): void {
    $db->executeStatement('UPDATE accounts SET balance = balance - ? WHERE id = ?', [$amount, $from]);
    $db->executeStatement('UPDATE accounts SET balance = balance + ? WHERE id = ?', [$amount, $to]);
});
```

- Both `UPDATE`s run on one connection in one transaction; neighbouring coroutines neither see nor can break
  it: the nesting level lives on this coroutine's lease.
- An exception inside → `rollBack()` and rethrow. An exception *outside* `transactional()` with an open
  transaction (e.g. `beginTransaction()` without `try/finally`) → on release the pool issues `ROLLBACK`, logs
  a warning and dispatches `RolledBackOnRelease`. No data is lost, but it is a handler bug — watch
  `rollbacksOnReleaseTotal`.
- Nested `beginTransaction()` → `SAVEPOINT`, as in DBAL.
- `ConnectionLost` mid-transaction → the server rolled the transaction back, the connection is destroyed, the
  next query runs on a fresh one. Whether to retry is the application's decision (see scenario 10).

## 3. ORM: an EntityManager per coroutine

The package makes DBAL coroutine-safe. `EntityManager` holds a UnitOfWork — one shared `EntityManager` under
concurrent coroutines mixes entities of different requests. Options:

**A. Synchronous handler code (the default situation).** One HTTP request — one coroutine — the handler code
is sequential; the container's shared `EntityManager` works, as long as the handler has no `go()` inside… but
a `flush()` of one request can pick up entities of another when requests interleave on I/O. To be safe:

**B. An EntityManager per coroutine.** Through a factory with the shared configuration:

```php
final class CoroutineEntityManagerFactory
{
    public function __construct(
        private readonly Connection $connection,       // CoroutineSafeConnection from the container
        private readonly Configuration $ormConfig,     // doctrine.orm.default_configuration
    ) {}

    public function create(): EntityManagerInterface
    {
        return new EntityManager($this->connection, $this->ormConfig);
    }
}
```

```php
$em = $this->factory->create();          // at the start of the handler or task
$em->wrapInTransaction(static function () use ($em, $order): void {
    $em->persist($order);
});
$em->close();
```

`IDENTITY` ids work: `lastInsertId()` goes to the same session as the `INSERT` — `OrmTest` proves it
(20 concurrent flushes, all ids unique).

## 4. Parallel queries inside one HTTP request

```php
$profile = $stats = null;
$group = new WaitGroup();

$group->add();
Coroutine::create(function () use ($userId, $group, &$profile): void {
    $profile = $this->db->fetchAssociative('SELECT * FROM profiles WHERE user_id = ?', [$userId]);
    $group->done();
});

$group->add();
Coroutine::create(function () use ($userId, $group, &$stats): void {
    $stats = $this->db->fetchAssociative('SELECT count(*) AS n FROM events WHERE user_id = ?', [$userId]);
    $group->done();
});

$group->wait();
```

- Every child coroutine gets its **own** connection (and releases it via defer when it ends). One request
  occupies `1 + N` connections — budget for it in `size`.
- A child coroutine is **outside the parent's transaction**: the parent's uncommitted changes are invisible
  to it. If you need to read inside the transaction, read sequentially in the parent.
- A `Statement` prepared by the parent cannot be executed from a child (`LeaseViolationException`) — prepare
  in the coroutine that executes.

## 5. Background daemons and ticking tasks

A daemon under a Swoole runtime is a long-lived coroutine. By default a lease lives until the coroutine ends,
i.e. forever: the connection drops out of the pool and is never reset. Release after every iteration:

```php
final class OutboxRelay // ticked by your runtime inside a coroutine
{
    protected function tick(): void
    {
        try {
            $rows = $this->db->fetchAllAssociative('SELECT * FROM outbox WHERE sent_at IS NULL LIMIT 100');
            // ... send, mark
        } finally {
            $this->db->close();      // return the connection before the next tick
        }
    }
}
```

`close()` inside a coroutine is exactly "release the lease"; the wrapper stays usable and the next query
takes a connection again. The same applies to `Swoole\Timer::tick` callbacks and task-worker handlers.

A daemon whose `run()` returns to let the process restart: with the default
`maintenance_interval: 0` the pool holds no timer, so nothing keeps the process alive; if you enabled the
timer, call `PoolRegistry::closeAll()` (or `Timer::clearAll()`) before returning.

## 6. Console commands and migrations

- `bin/console` under a runtime that executes the command inside a coroutine:
  the pool works, `console.terminate` returns the connection.
- Plain `bin/console` without the Swoole runtime or without `Coroutine\run()` — direct mode: one PDO without
  a pool, timers or `defer`; `doctrine:migrations:migrate`, `doctrine:schema:*` work as always.
- A console application that runs commands inside `Coroutine\run()` **without hooks** (a common pattern): the
  pool works, PDO simply blocks the single coroutine — harmless; a warning is logged once. On
  `console.terminate` the bundle closes the pools so the command can exit.
- A long command over millions of rows in direct mode holds one connection — as without the pool.

A separate "direct" connection in `doctrine.yaml` for the console is no longer needed.

## 7. Multiple databases

```yaml
doctrine:
  dbal:
    connections:
      default:   { url: '%env(DATABASE_URL)%',   server_version: '17', idle_connection_ttl: 0 }
      analytics: { url: '%env(ANALYTICS_URL)%',  server_version: '17', idle_connection_ttl: 0 }
      legacy:    { url: '%env(LEGACY_URL)%',     server_version: '13' }

swoole_doctrine_pool:
  connections:
    default:   { size: 20 }
    analytics: { size: 4, acquire_timeout: 10.0 }   # heavy queries, wait longer
    # legacy is not listed: stays on plain pdo_pgsql
```

One pool per DSN (`host/port/dbname/user/password/...`); two connections with identical parameters share
a pool. A coroutine may hold one lease in each pool at the same time; transactions of different databases
are independent (there is no two-phase commit).

## 8. Graceful shutdown and reload

With a runtime that dispatches the `swoole.*` events there are two phases. `swoole.worker_exit` (fired under `reload_async` — on
`max_request`, a memory restart or a reload — while the old worker still serves its in-flight requests)
→ `drainAll()`: timers stopped, idle closed, returned connections closed, **new leases still handed out**, so
a request that has not touched the DB yet still succeeds. `swoole.worker_stop` (all coroutines finished)
→ `closeAll(5.0)`: waits for leased connections, then wakes waiters with `PoolClosedException`. With
`max_request: 1000` every worker goes through this once per thousand requests — without the drain phase each
recycle would fail the requests that were in flight.

Your own runtime:

```php
$server->on('workerStop', static fn() => $registry->closeAll(drainTimeout: 10.0));
```

Keep `drainTimeout` below the orchestrator's `terminationGracePeriodSeconds`.

## 9. Warming up connections on worker start

The first request after a start pays for `connect` (tens of milliseconds with TLS). To avoid that:

```yaml
swoole_doctrine_pool:
  connections:
    default: { size: 20, min_idle: 4 }
```

On `swoole.worker_start` the bundle opens 4 connections; maintenance keeps their number after idle periods.
Standalone: `(new Driver(registry: $registry))->poolFor($params)->warmUp()` in `workerStart`.

## 10. Failover, Postgres restarts, pgbouncer

- A connection that died **while idle** is caught by `validate_idle_after` (by default a `SELECT 1` for
  connections idle > 5 s) — the application's query goes through without an error on a fresh connection.
- A connection that died **during a query** yields `Doctrine\DBAL\Exception\ConnectionLost`: the connection
  is destroyed, the server rolled the transaction back. Retrying is up to the application, and only for
  idempotent operations:

```php
try {
    return $this->db->transactional($work);
} catch (ConnectionLost) {
    return $this->db->transactional($work);   // one retry on a fresh connection
}
```

- After a failover, old connections to the former primary live until `max_lifetime` (1 h by default) or until
  an error. For a faster switch use a smaller `max_lifetime` or call `closeAll()` on an external signal.
- **pgbouncer in transaction mode**: server-side prepared statements are disabled (as DBAL does by default),
  `DISCARD ALL` is compatible with it. `application_name` in the DSN helps tell workers apart in
  `pg_stat_activity`.

## 11. Metrics and health check

```php
final class PoolHealthController
{
    public function __construct(private readonly PoolStatsProviderInterface $stats) {}

    #[Route('/health/db-pool')]
    public function __invoke(): JsonResponse
    {
        $pools = array_map(static fn(PoolStats $s): array => $s->toArray(), $this->stats->snapshot());
        $degraded = array_filter($pools, static fn(array $p): bool => $p['acquire_timeouts_total'] > 0);

        return new JsonResponse(['pools' => $pools], $degraded ? 503 : 200);
    }
}
```

The snapshot covers the pools of the **current worker**; aggregate by `worker_id`. Prometheus: see
[stats.md](stats.md). Counters worth alerting on: `acquire_timeouts_total`, `rollbacks_on_release_total`,
`validation_failures_total`.

## 12. Long reads and large results

`iterateAssociative()` / `Result::fetchAssociative()` read a **buffered** result: pdo_pgsql fetches the whole
result from the server at `execute()`. Therefore:

- iterating a result does not keep the socket busy, but it keeps memory for all rows;
- a result can be read from another coroutine (don't rely on it — but it is not an error);
- for millions of rows use `LIMIT/OFFSET` or keyset pagination; server-side cursors are not provided by the
  package (they need a transaction for the whole read — allowed, but the lease stays busy).

## 13. Session state: `SET`, advisory locks, LISTEN

By default (`reset_on_release: discard`) `DISCARD ALL` runs on release: whatever you set on the session goes
away with the request. Consequences:

- `SET search_path`/`SET timezone` inside a request is safe and lasts until the end of the request.
- A session-level `pg_advisory_lock()` is released on release. For a lock spanning several requests use
  `pg_advisory_xact_lock()` inside a transaction or an external lock (e.g. Symfony Lock).
- `LISTEN` does not survive release; for subscriptions keep a dedicated connection outside the pool.
- `rollback_only` keeps all of it — and the next request on the same connection inherits the `search_path`.

## 14. Testing an application with the pool

`phpunit` without `Coroutine\run()` — direct mode: one connection, transactions work, no pool.
Tests inside `Coroutine\run()` — a real pool. Details in [testing.md](testing.md).

## 15. Postgres connection limit

`size × workers (× instances)` ≤ `max_connections` minus headroom for console, migrations and monitoring.
Example: 8 workers × `size: 20` = 160 with `max_connections = 200`. If it does not fit — pgbouncer between
the application and Postgres (scenario 10) or a smaller `size` with a larger `acquire_timeout`.
How to pick `size` from RPS and hold time, and when a bouncer is actually needed —
[configuration.md → Sizing](configuration.md#sizing).
