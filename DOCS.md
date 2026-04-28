# karhu-queue — Project Documentation

**Version:** 0.1.0 | **License:** MIT | **PHP:** >=8.3

Minimal queue/worker abstraction for the [karhu](https://github.com/bjornbasar/karhu) PHP microframework. Ships with a `DatabaseQueue` driver; bring your own for Redis, RabbitMQ, etc.

---

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP 8.3+ |
| Default driver | Database-backed (karhu-db) |
| Autoloading | Composer PSR-4 (`Karhu\Queue\`) |

Zero runtime dependencies. karhu-db is *suggested* (only required if you use `DatabaseQueue`).

---

## Directory Structure

```
karhu-queue/
├── src/
│   ├── QueueInterface.php   # push/pop/markDone/markFailed contract
│   ├── DatabaseQueue.php    # karhu-db backed driver — single `jobs` table
│   └── Worker.php           # Job dispatcher — register handler closures, run loop
└── composer.json
```

---

## API Surface

### `Karhu\Queue\QueueInterface`

| Method | Description |
|---|---|
| `push(string $job, array $data, string $queue = 'default'): string` | Enqueue. Returns job ID. |
| `pop(string $queue = 'default'): ?array` | Pull next pending job (atomic — driver decides locking). |
| `markDone(string $id): void` | Mark complete. |
| `markFailed(string $id, string $error): void` | Mark failed with error message. |

### `Karhu\Queue\DatabaseQueue`

Single-table driver — uses karhu-db's `Connection`. Status transitions: `pending` → `processing` → `done` / `failed`.

```sql
CREATE TABLE jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(50) NOT NULL DEFAULT 'default',
    job VARCHAR(255) NOT NULL,
    data TEXT NOT NULL DEFAULT '{}',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

Schema works on SQLite, MySQL, and PostgreSQL — only `AUTOINCREMENT` keyword needs swapping (`AUTOINCREMENT` / `AUTO_INCREMENT` / `SERIAL`).

### `Karhu\Queue\Worker`

```php
$worker = new Worker($queue);
$worker->register('SendEmail', function (array $data) {
    mail($data['to'], $data['subject'], $data['body']);
});
$worker->run();   // blocking loop — calls $queue->pop(), dispatches, marks done/failed
```

`run()` loops until interrupted. Sleep cadence and shutdown-signal handling are intentionally caller-controlled — wire into your supervisor/systemd of choice.

---

## Key Design Decisions

- **Zero runtime deps** — drivers are opt-in via the `suggest` block.
- **Interface-first** — `QueueInterface` is the contract; `DatabaseQueue` is one implementation. Redis / RabbitMQ adapters can ship as separate packages without touching this one.
- **Single-table schema** — easy to inspect, easy to migrate, single index on `(queue, status, id)` covers the worker hot path.
- **No retry logic** — `markFailed` is terminal in v0.1. Retry policies belong in the worker handler (re-enqueue with a delay attribute).
- **Synchronous worker** — no concurrency primitives in the package itself. Run multiple `Worker::run()` processes for parallelism.

---

## Custom drivers

Implement `QueueInterface` and inject your driver where you'd otherwise pass `DatabaseQueue`:

```php
final class RedisQueue implements QueueInterface {
    public function push(string $job, array $data, string $queue = 'default'): string { /* ... */ }
    public function pop(string $queue = 'default'): ?array { /* ... */ }
    public function markDone(string $id): void { /* ... */ }
    public function markFailed(string $id, string $error): void { /* ... */ }
}
```

---

## Development

```bash
composer install
# No test suite shipped in this package yet — driven by integration tests in apps that use it
```

---

## Related Repos

| Repo | Purpose |
|------|---------|
| [karhu](https://github.com/bjornbasar/karhu) | Parent microframework |
| [karhu-db](https://github.com/bjornbasar/karhu-db) | PDO wrapper used by `DatabaseQueue` |
