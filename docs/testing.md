**English** | [Русский](testing.ru.md)

# Testing

## Testing an application that uses the pool

### Unit and functional tests without the Swoole scheduler

`phpunit` runs tests outside a coroutine (`Coroutine::getCid() === -1`) — the driver works in **direct mode**:
one PDO connection, transactions, `close()` — everything as with plain `pdo_pgsql`, no pool and no timers.
No configuration changes are needed; `dama/doctrine-test-bundle` and similar transaction wrappers work.

If the test environment has no ext-swoole at all, the package still loads: `Swoole\*` is needed only when
a pool is created.

### Tests inside coroutines

To test code that spawns coroutines or depends on the pool:

```php
public function testParallelLookups(): void
{
    $error = null;

    \Swoole\Coroutine\run(function () use (&$error): void {
        try {
            $service = self::getContainer()->get(ProfileService::class);
            self::assertCount(2, $service->loadBoth(42));
        } catch (\Throwable $e) {
            $error = $e;
        } finally {
            self::getContainer()->get(PoolRegistry::class)->closeAll();   // the pool timer keeps the event loop alive
        }
    });

    if ($error !== null) {
        throw $error;
    }
}
```

Two rules:

- **`closeAll()` in `finally`** — the pool's maintenance timer prevents `run()` from returning.
- **Hooks via `Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])` in the bootstrap**, not
  `Runtime::enableCoroutine()`: after a global enable, PDO outside a coroutine (in `setUp`, fixtures) throws
  `Swoole\Error: API must be called in the coroutine`.

Exceptions from child coroutines do not propagate — collect them into a variable as above.

### Fixtures and schema

Prepare the schema with plain `PDO`/DBAL in `setUpBeforeClass` outside a coroutine (direct mode), and run
concurrent scenarios inside `run()`. One schema per test class (`CREATE SCHEMA t_<class>`) keeps tests
independent and parallelisable.

## The package's own tests

| Suite | Command | Needs |
|---|---|---|
| `unit` | `composer test-unit` | nothing: no ext-swoole, no Postgres, fakes only |
| `integration` | `composer up && composer integration` | docker: Swoole 6.2.3 + Postgres 17 |
| everything for CI | `composer ci` | phpcs + psalm + unit |

`tests/Integration/run.sh` builds an image for every `BASE_IMAGE` in the matrix (default
`phpswoole/swoole:6.2.3-php8.5-zts`; `SWOOLE_IMAGES="…"` for your own), mounts the repository at `/app`
and runs the suite against the Postgres from `compose.yaml`. Inside the container the tests find the database
through `POOL_TEST_DSN`; without it the suite is skipped.

What the integration tests prove and which change would make each of them red —
[tests/Integration/README.md](../tests/Integration/README.md).

### Fakes for unit tests

`tests/Unit/Fake/`: `FakeCoroutineApi` (switchable cid, defers per cid, timers), `FakePdo` (no connection,
call log, failures by method name), `FakeInnerDriver`, `FakeConnectionFactory`, `RecordingDispatcher`,
`RecordingLogger`. `CountingSlots` is a non-waiting semaphore standing in for `ChannelSlots`. Through them the
accounting invariants, the `ROLLBACK → DISCARD ALL` order, per-cid transaction isolation and lost-connection
behaviour are verified — without Swoole and without a database.
