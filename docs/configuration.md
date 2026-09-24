**English** | [Русский](configuration.ru.md)

# Configuration

There is one source of defaults — `SwooleDoctrinePool\Config\PoolConfig::DEFAULT_*`; the Symfony tree and
standalone mode read the same keys.

## Symfony

```yaml
swoole_doctrine_pool:
  connections:
    default:                      # a name from doctrine.dbal.connections
      enabled: true
      size: 10
      min_idle: 0
      acquire_timeout: 5.0
      max_lifetime: 3600
      idle_timeout: 300
      max_uses: 0
      validate_idle_after: 5.0
      reset_on_release: discard
      maintenance_interval: 0
    analytics: ~                  # every value at its default
    legacy:
      enabled: false              # stays on plain pdo_pgsql
```

## Without Symfony

The same keys under `driverOptions['pool']` of the DBAL parameters:

```php
'driverOptions' => [
    'pool' => ['size' => 10, 'reset_on_release' => 'discard'],
    PDO::ATTR_TIMEOUT => 5,        // integer keys go to PDO untouched
],
```

## Keys

| Key | Type | Default | Meaning |
|---|---|---|---|
| `enabled` | bool | `true` | `false` leaves the connection on DoctrineBundle's stock driver |
| `size` | int ≥ 1 | 10 | Maximum number of simultaneously open pool connections **in one worker**. It is also the cap on concurrent DB requests per worker: a lease is held until the end of the HTTP request. `size × workers ≤ max_connections` |
| `min_idle` | int 0..size | 0 | How many idle connections to keep open. Opened on `swoole.worker_start` (Symfony) or `PoolRegistry::warmUpAll()`; maintained by the reaper |
| `acquire_timeout` | float > 0, s | 5.0 | How long to wait for a free connection when all `size` are busy. Afterwards — `PoolExhaustedException` (extends `Doctrine\DBAL\Exception\ConnectionException`), no lease is handed out |
| `max_lifetime` | int ≥ 0, s | 3600 | Lifetime of a physical connection; older ones are closed on release or by maintenance. Survives failover, pgbouncer restarts, backend memory growth. `0` — unlimited |
| `idle_timeout` | int ≥ 0, s | 300 | Idle longer than this — closed by maintenance, but never below `min_idle`. `0` — never |
| `max_uses` | int ≥ 0 | 0 | After how many leases to close the connection forcibly. A safety net against session-state leaks under `rollback_only`. `0` — unlimited |
| `validate_idle_after` | float ≥ 0 \| `~`, s | 5.0 | Before handing out a connection idle longer than this, run `SELECT 1`; a dead one is silently replaced. `0` — always (a round-trip on every acquire from idle), `~` — never |
| `reset_on_release` | `discard` \| `rollback_only` | `discard` | `discard` — `DISCARD ALL` on every release: resets `SET`, the session isolation level, temp tables, advisory locks, LISTEN. `rollback_only` — only the rollback; one round-trip cheaper, session state survives the request. **The `ROLLBACK` of an unfinished transaction happens in both modes** |
| `maintenance_interval` | int ≥ 0, s | 0 | `0` — no timer: expired idle connections are closed lazily when a connection is returned (nothing closes during total silence — until the next release). A positive value adds a `Swoole\Timer` that also sweeps during silence and keeps `min_idle` topped up. A live timer keeps a console command or a custom daemon from exiting and makes `reload_async` wait — enable it only in HTTP workers |

## Values from env

Symfony: numeric nodes accept typed placeholders — `size: '%env(int:DB_POOL_SIZE)%'`,
`acquire_timeout: '%env(float:DB_POOL_ACQUIRE)%'`. `validate_idle_after` accepts any placeholder;
`PoolConfig` parses it at runtime (`""`, `null`, `~` → "never").

Standalone: `PoolConfig::fromArray()` coerces strings itself: `"10"` → 10, `"2.5"` → 2.5, `""`/`"null"`/`"~"` → null.
An unknown key is an `InvalidConfigurationException` (protection against typos like `poolSize`).

## Sizing

The right pool is a **small** one. Postgres throughput is bounded by cores and disks, not by connections:
the practical optimum of *active* connections per instance is about `2 × cores + disks` (≈ 16–20 on an
8-core SSD box) — for the whole cluster, not per worker. Above that, context switches and lock contention
make throughput fall.

### Formula

```
size                          = ceil(rps_per_worker × avg_hold_seconds) + headroom
size × worker_num × instances ≤ ~2 × cores of Postgres        (otherwise: pgBouncer, see below)
size × worker_num × instances ≤ max_connections − headroom for console, migrations, monitoring
```

`avg_hold_seconds` is how long a request **holds** the connection — from its first query until
`kernel.terminate` (or an explicit `close()`), not only the SQL time. It is reported by
`LeaseReleased::heldSeconds`.

Example: 10 workers, 300 RPS per instance, 20 ms hold → 6 busy connections per instance, i.e. less than one
per worker. `size: 10` would keep 100 connections per instance — 15× more than needed, and 500 with five
instances: bad for Postgres.

### Starting profile for high load

```yaml
swoole_doctrine_pool:
  connections:
    default:
      size: 4                  # 4 × 10 workers = 40 per instance; raise only on acquire_timeouts_total
      min_idle: 2              # warm after worker start/recycle (max_request makes recycles frequent)
      acquire_timeout: 2.0     # below the load balancer timeout: a fast 503 beats a queue
      max_lifetime: 1800
      idle_timeout: 60         # give capacity back after a burst
      validate_idle_after: 5.0
      reset_on_release: discard
      maintenance_interval: 10 # HTTP workers only: keeps min_idle and sweeps during silence
```

Swoole side: `worker_num` ≈ CPU cores of the instance (PHP is CPU-bound), a large `max_request`
(each recycle rebuilds the pool).

### What matters more than `size`

1. **Hold time.** The connection is held until `terminate`, including outbound HTTP calls, rendering and
   serialisation after the last query. If a handler does long work after its last SQL, call
   `$connection->close()` right after it — `size` can then be halved.
2. **`rollback_only`** removes one round-trip per request (~0.1–0.3 ms + RTT) — only if the code never touches
   session state (`SET`, `SET SESSION CHARACTERISTICS`, `setTransactionIsolation()`, temp tables, advisory
   locks). A safety net for that mode: `max_uses: 1000`.
3. **`server_version`** in the DBAL configuration: the platform is built without a connection.
4. **Do not raise `size` reactively** on exhaustion. First look at why the hold is long (a slow query, an N+1,
   waiting on an external service while holding the connection). A big pool with slow queries only moves the
   queue into Postgres, where it is more expensive.
5. **Separate pools for separate workloads.** Long analytical queries get their own DBAL connection and pool
   (`analytics: { size: 2, acquire_timeout: 30 }`) so they cannot eat the OLTP slots.

### Tune by these metrics

| Signal | Meaning |
|---|---|
| `waiting > 0` persistently | `size` is too small — or the hold is too long |
| `acquire_timeouts_total` grows | same; clients get `PoolExhaustedException` (503) |
| `idle ≈ size` all the time | `size` is oversized; lower it and free Postgres memory |
| `created_total` grows fast | worker recycles or a short `max_lifetime` |
| `rollbacks_on_release_total > 0` | handlers leave transactions open — fix the code |

### When a small pool is not enough

- **Many instances/pods.** The pool bounds connections per worker, not per cluster: 20 pods × 10 workers ×
  4 = 800 connections. Put **pgBouncer in transaction mode** (or PgCat/Odyssey) between the application and
  Postgres; `size` may stay as is — the bouncer holds the real limit. The pool is already compatible:
  server-side prepared statements are disabled, `DISCARD ALL` is what the bouncer expects. Transaction-mode
  limits still apply (`LISTEN`, session advisory locks, temp tables across transactions).
- **Failover without restarts** — pgBouncer's `PAUSE/RESUME` is smoother than waiting for
  `ConnectionLost` + `max_lifetime`.

Otherwise pgBouncer is not needed: the pool already reuses connections, resets sessions and bounds their number.

### Other keys

- `acquire_timeout` below the load balancer / client timeout, otherwise the client leaves before the server
  answers 503.
- `idle_timeout` below `max_lifetime`; `min_idle` — how many connections you want hot after an idle period.
