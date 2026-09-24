[English](stats.md) | **Русский**

# Статистика и метрики

`SwooleDoctrinePool\Stats\PoolStatsProviderInterface::snapshot()` возвращает `array<label, PoolStats>` по всем
пулам **текущего воркера** (в Symfony — сервис, реализация `RegistryStatsProvider`). Тот же снимок дают
`PoolRegistry::stats()` и `Pool::stats()`.

| Поле | Смысл | Норма |
|---|---|---|
| `label` | `user@host:port/dbname` | |
| `size` | лимит | |
| `idle` | простаивают в пуле | |
| `inUse` | на руках у корутин | ≈ число одновременных запросов |
| `connecting` | открываются прямо сейчас | |
| `waiting` | корутин ждут слот | 0 — иначе `size` мал |
| `createdTotal` / `closedTotal` | открыто / закрыто за время жизни пула | растут медленно; быстро — маленький `max_lifetime`/`max_uses` или обрывы |
| `acquiredTotal` | выдач | |
| `acquireTimeoutsTotal` | исчерпаний (`PoolExhaustedException`) | 0 |
| `rollbacksOnReleaseTotal` | откатов при возврате | 0 — иначе обработчики не закрывают транзакции |
| `validationFailuresTotal` | мёртвых соединений, пойманных `SELECT 1` | ~0; рост — сеть/idle-killer |

`open()` = `idle + inUse + connecting` ≤ `size`. `toArray()` — snake_case для экспорта.

## Prometheus

Экспортёра в пакете нет — снимок отдаётся вашему коллектору. Пример с `promphp/prometheus_client_php`
(коллектор вызывается при скрейпе `/metrics`):

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

            $this->prometheus->getOrRegisterGauge('app', 'db_pool_in_use', 'Соединений на руках', ['pool', 'worker'])
                ->set($s->inUse, $labels);
            $this->prometheus->getOrRegisterGauge('app', 'db_pool_idle', 'Простаивающих соединений', ['pool', 'worker'])
                ->set($s->idle, $labels);
            $this->prometheus->getOrRegisterGauge('app', 'db_pool_waiting', 'Ожидающих корутин', ['pool', 'worker'])
                ->set($s->waiting, $labels);
            $this->prometheus->getOrRegisterCounter('app', 'db_pool_acquire_timeouts_total', 'Исчерпаний', ['pool', 'worker'])
                ->incBy(0, $labels);   // значение берите из snapshot как gauge либо считайте по событиям
        }
    }
}
```

Счётчики (`*_total`) — накопительные с момента создания пула; для честного Prometheus-counter
удобнее инкрементировать по [событиям](events.ru.md) (`AcquireTimedOut`, `RolledBackOnRelease`,
`ConnectionClosed`) или отдавать снимок как gauge.

## Алерты

- `acquire_timeouts_total` растёт → мало `size` или запросы держат соединение слишком долго.
- `rollbacks_on_release_total` растёт → обработчики выходят с открытой транзакцией.
- `waiting > 0` устойчиво → близко к исчерпанию.
- `closed_total` растёт быстро при ровной нагрузке → обрывы соединений или слишком агрессивный `max_lifetime`.
