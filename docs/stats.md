**English** | [Русский](stats.ru.md)

# Statistics and metrics

`SwooleDoctrinePool\Stats\PoolStatsProviderInterface::snapshot()` returns `array<label, PoolStats>` for all
pools of the **current worker** (in Symfony it is a service, implemented by `RegistryStatsProvider`). The same
snapshot comes from `PoolRegistry::stats()` and `Pool::stats()`.

| Field | Meaning | Healthy |
|---|---|---|
| `label` | `user@host:port/dbname` | |
| `size` | the limit | |
| `idle` | idle in the pool | |
| `inUse` | held by coroutines | ≈ number of in-flight requests |
| `connecting` | being opened right now | |
| `waiting` | coroutines waiting for a slot | 0 — otherwise `size` is too small |
| `createdTotal` / `closedTotal` | opened / closed over the pool's lifetime | grow slowly; fast growth means a small `max_lifetime`/`max_uses` or dropped connections |
| `acquiredTotal` | hand-outs | |
| `acquireTimeoutsTotal` | exhaustions (`PoolExhaustedException`) | 0 |
| `rollbacksOnReleaseTotal` | rollbacks on release | 0 — otherwise handlers leave transactions open |
| `validationFailuresTotal` | dead connections caught by `SELECT 1` | ~0; growth means network trouble or an idle killer |

`open()` = `idle + inUse + connecting` ≤ `size`. `toArray()` gives snake_case keys for export.

## Prometheus

The package ships no exporter — the snapshot is handed to your collector. An example with
`promphp/prometheus_client_php` (the collector is called on a `/metrics` scrape):

```php
final class PoolMetricsCollector
{
    public function __construct(
        private readonly PoolStatsProviderInterface $stats,
        private readonly CollectorRegistry $prometheus,
    ) {}

    public function collect(int $workerId): void
    {
        foreach ($this->stats->snapshot() as $label => $s) {
            $labels = ['pool' => $label, 'worker' => (string)$workerId];

            $this->prometheus->getOrRegisterGauge('app', 'db_pool_in_use', 'Connections held', ['pool', 'worker'])
                ->set($s->inUse, $labels);
            $this->prometheus->getOrRegisterGauge('app', 'db_pool_idle', 'Idle connections', ['pool', 'worker'])
                ->set($s->idle, $labels);
            $this->prometheus->getOrRegisterGauge('app', 'db_pool_waiting', 'Waiting coroutines', ['pool', 'worker'])
                ->set($s->waiting, $labels);
            $this->prometheus->getOrRegisterGauge('app', 'db_pool_acquire_timeouts', 'Exhaustions', ['pool', 'worker'])
                ->set($s->acquireTimeoutsTotal, $labels);
        }
    }
}
```

The `*_total` values are cumulative since the pool was created; for a proper Prometheus counter increment on
[events](events.md) (`AcquireTimedOut`, `RolledBackOnRelease`, `ConnectionClosed`) or export the snapshot
as gauges.

## Alerts

- `acquire_timeouts_total` grows → `size` is too small or requests hold connections too long.
- `rollbacks_on_release_total` grows → handlers exit with an open transaction.
- `waiting > 0` persistently → close to exhaustion.
- `closed_total` grows fast under steady load → dropped connections or an aggressive `max_lifetime`.
