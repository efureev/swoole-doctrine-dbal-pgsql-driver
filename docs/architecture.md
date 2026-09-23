**English** | [Русский](architecture.ru.md)

# Architecture

## Layers

```
Doctrine\DBAL\Connection ── CoroutineSafeConnection        application / ORM
   │  transaction state → on the current coroutine's lease
   └─ VirtualConnection (Driver\Connection, stateless)
        │  on every call: the current coroutine's lease from Coroutine::getContext()
        └─ Lease ── PhysicalConnection (PDO)  ←  Pool for this DSN  ←  PoolRegistry (one per worker)
```

| Component | Responsibility |
|---|---|
| `Driver\Driver` | the DBAL driver (`driverClass`): `connect()` is cheap and does no I/O, returns a `VirtualConnection`; platform and exception converter come from the inner Postgres driver |
| `Driver\PgsqlDriver` | opens the physical PDO: DSN from the DBAL parameters, `ERRMODE_EXCEPTION`, server-side prepared statements disabled, `SET NAMES`. It replaces DBAL's stock `Driver\PDO\PgSQL\Driver`, which on PHP ≥ 8.4 needs the `Pdo\Pgsql` class that Swoole's built-in `pdo_pgsql` does not provide |
| `Driver\PoolDriverMiddleware` | a `Doctrine\DBAL\Driver\Middleware`: moves any Postgres driver onto the pool and injects the shared `PoolRegistry`. Must be the innermost middleware |
| `Driver\VirtualConnection` | the driver-level connection for the wrapper's whole lifetime; routes each call to the coroutine's lease, outside a coroutine — to one direct PDO |
| `Driver\LeaseAwareStatement` / `LeaseAwareResult` | ownership checks: a statement from another coroutine or after release → `LeaseViolationException`; a result cannot be read after release |
| `Driver\PoolExceptionConverter` | on top of the stock PostgreSQL converter: `08xxx`/`57P0x`/known messages → `ConnectionLost`; pool exhaustion → `PoolExhaustedException` |
| `DBAL\CoroutineSafeConnection` | the wrapper (`wrapperClass`): `beginTransaction/commit/rollBack/…` operate on the `TransactionState` of the current coroutine's lease; `close()` = release the lease |
| `Lease\Lease`, `LeaseSet`, `LeaseBinder` | connection ownership by a coroutine: coroutine context, `defer`, idempotent release |
| `Pool\Pool` | semaphore + idle stack + accounting; acquire/release/maintain/drain/close |
| `Pool\Slots` (`ChannelSlots`) | a counting semaphore on `Swoole\Coroutine\Channel`: FIFO waiting with a timeout |
| `Pool\PoolRegistry` | the worker's pools by DSN, the shared `LeaseBinder`, `closeAll()`, statistics, fork protection |
| `Config\PoolConfig` | settings with validation and coercion of env strings |
| `Event\*`, `Stats\*` | PSR-14 events, state snapshots |
| `Bridge\Symfony\*` | the bundle: configuration, compiler pass, listeners |

## Lease

- Taken **lazily** on the coroutine's first query, bound to its `cid`.
- Lives until **release**: `kernel.terminate`/`console.terminate` (Symfony), `$connection->close()`,
  `PoolRegistry::releaseCurrentCoroutine()` or — the safety net — `Coroutine::defer` when the coroutine ends.
- Deliberately not statement-scoped: `lastInsertId()` after an `INSERT` and the whole transaction must run on
  one session.
- A child coroutine has its own context → its own lease → **outside the parent's transaction**.
- One coroutine may hold a lease in every pool (several databases).

## Acquire

1. Take a semaphore token (`Channel::pop(acquire_timeout)`). A token is the right to hold a connection; it is
   taken **before** connecting, so no more than `size` connections are ever opened, however many coroutines
   arrive at once. Timeout → `AcquireTimeoutException` → at the wrapper level `PoolExhaustedException`.
2. Pop a connection from the top of the idle stack (LIFO — the warmest one). One expired by
   `max_lifetime`/`max_uses` is closed; one idle longer than `validate_idle_after` is checked with `SELECT 1`,
   a dead one is closed — and loop.
3. Idle is empty → open a new connection through `PgsqlDriver`.

## Release (never throws)

1. The connection is marked broken → destroy.
2. `PDO::inTransaction()` (server-side state — it sees a raw `BEGIN` too) → `ROLLBACK`, a warning in the log,
   the `RolledBackOnRelease` event. Rollback failure → destroy.
3. The pool is closing / the connection has expired → destroy.
4. `reset_on_release: discard` → `DISCARD ALL`. Failure → destroy.
5. Into idle; the token goes back to the semaphore — that wakes a waiting acquire.
6. Lazy sweep from the bottom of the idle stack: connections expired by `max_lifetime`/`max_uses` are closed,
   those idle longer than `idle_timeout` — while idle is above `min_idle`.

The pool **never commits** on the application's behalf.

## Accounting

Tokens count `inUse + connecting`; an idle connection holds no token. Invariants that the tests assert after
any sequence of operations:

- `inUse + connecting + free tokens == size`;
- `idle + inUse + connecting ≤ size`.

Nothing depends on the GC (no `WeakMap`), idle is never drained through a channel — there is nowhere to lose
connections.

## Maintenance and lifecycle

- `maintenance_interval: 0` (default) — no timer; the lazy sweep on release is the only reaper. Nothing closes
  during total silence, which is harmless: a connection idle longer than `validate_idle_after` is checked before
  reuse. A positive interval adds a `Swoole\Timer` that sweeps in silence and keeps `min_idle` topped up.
- `drain()` — the worker is leaving but still serves in-flight requests (`worker_exit` under `reload_async`,
  `max_request`, memory restarts): the timer is stopped, idle connections closed, returned connections are
  closed instead of stacked, **new leases are still handed out**.
- `close()` — full shutdown (`worker_stop`, `console.terminate`): after drain, wait for leased connections up to
  `drainTimeout`, then close the semaphore so waiters get `PoolClosedException`.

## Execution flows

**First query in a coroutine**

```
executeQuery → CoroutineSafeConnection::connect() → VirtualConnection::prepare()
  → LeaseBinder::current(hash) = null → Pool::acquire()
      → Slots::acquire(timeout) [token] → idle empty → PgsqlDriver::connect() [yield]
  → new Lease(ownerCid) → Coroutine::defer(release) → Context[leases][hash] = lease
  → LeaseAwareStatement::execute() → LeaseAwareResult
second query of the same coroutine: a Context lookup, the pool is not touched
```

**Nested transaction**

```
beginTransaction  level 0→1: PDO::beginTransaction on the lease
beginTransaction  level 1→2: SAVEPOINT DOCTRINE_2 (same lease)
rollBack          level 2→1: ROLLBACK TO SAVEPOINT DOCTRINE_2
commit            level 1→0: PDO::commit
```

**Coroutine ended with an open transaction**

```
defer → Lease::release(viaDefer) → Pool::release
  → inTransaction() → ROLLBACK → warning + RolledBackOnRelease → DISCARD ALL → idle
```

**Connection lost during a query**

```
PDOException 08006 → LeaseAwareStatement marks the lease broken → DBAL converts
  → PoolExceptionConverter → ConnectionLost → DBAL calls the wrapper's close()
  → Lease::release() → Pool::destroy(Broken) → ConnectionClosed(broken)
the exception reaches the application; the next query takes a fresh connection
```

**Exhaustion**

```
Pool::acquire → Slots::acquire(5.0) waits (FIFO) → TIMEOUT → AcquireTimedOut(stats)
  → AcquireTimeoutException → PoolExhaustedException (ConnectionException)
no lease bound, no defer registered, no token spent
```

**Worker recycle and shutdown**

```
swoole.worker_exit  (repeats while the loop is not empty) → PoolRegistry::drainAll() → Pool::drain
  → timer cleared → idle closed → in-flight requests keep acquiring; releases destroy (Drained)
swoole.worker_stop  → PoolRegistry::closeAll(5.0) → Pool::close
  → wait for inUse == 0 → Slots::close() → waiters get PoolClosedException → PoolClosed(stats)
console.terminate   → releaseCurrentCoroutine() + closeAll() (a live timer would keep Coroutine\run() alive)
```

## Data-safety guarantees

| Failure | How it is ruled out |
|---|---|
| One PDO shared by two coroutines | the owner is fixed at hand-out; child coroutines have their own context and lease |
| Statement/Result used after the connection went to another coroutine | `LeaseAwareStatement::execute` checks release and cid; `LeaseAwareResult` checks release |
| Implicit commit | nowhere: release only rolls back; `close()` rolls back; `closeAll()` destroys leased connections after their own rollback |
| Transaction state visible to another coroutine | level, rollback-only, isolation live on the lease; `isTransactionActive()` answers for the current coroutine |
| The wrapper believes a transaction is open on a connection that is gone | lease released ⇒ no state ⇒ level 0; commit/rollBack post-actions check that the state is still theirs |
| A dead connection returned to the pool | `markBroken` on a lost-class error; any error on the release path → destroy |
| Retry of a non-idempotent operation after a lost connection | there are no automatic retries |
| Session state leaking between requests | `DISCARD ALL` by default |
| More than `size` connections under a burst | the token is taken before connecting |
| Connections lost to accounting bugs | no GC-dependent structures; invariants are asserted by tests |
| Forgotten release on an exception | `Coroutine::defer` is registered at hand-out |
| A pool from the master process after fork | the registry discards pools of a foreign pid with a warning, without closing the shared sockets |
| PDO without the hook blocking the worker | a warning once per registry when a pool is created in a coroutine without `SWOOLE_HOOK_PDO_PGSQL` (a loss of concurrency, not of data; console and master legitimately run without hooks) |
| `lastInsertId()` reading another session's `lastval` | the lease spans the whole request |
| Requests in flight losing the DB on worker recycle | `worker_exit` only drains; new leases are handed out until `worker_stop` |

## Direct mode

Outside a coroutine (`Coroutine::getCid() === -1`): one direct PDO without a pool, timers or defer; the
transaction state lives in the wrapper; `close()` rolls back an unfinished transaction and closes the PDO.
A Swoole limitation: with hooks enabled globally, PDO outside a coroutine is forbidden — set the hooks via
`Coroutine::set(['hook_flags' => …])` if the script also runs outside `Coroutine\run()`.

## Fork and threads

Pools are created lazily inside the worker. There are no static properties in `src/` (a test checks that):
all state lives in `PoolRegistry`, and under SWOOLE_THREAD isolation between threads comes for free.

## Performance

- The hot path of a coroutine that already holds a lease: a context read plus two checks; the pool is not involved.
- The LIFO idle stack keeps connections warm: `SELECT 1` almost never runs under steady load.
- `rollback_only` removes one round-trip per release; `ROLLBACK` runs only when a transaction is open
  (by `PQtransactionStatus`, no query).
- The server version is cached from `PDO::ATTR_SERVER_VERSION`; with `server_version` in the configuration the
  platform is built without touching the DB.
- Events are constructed only when a dispatcher is present.
