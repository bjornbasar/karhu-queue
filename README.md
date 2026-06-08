# karhu-queue

Minimal queue/worker abstraction for the [karhu](https://github.com/bjornbasar/karhu) PHP microframework.

## Install

```bash
composer require bjornbasar/karhu-queue bjornbasar/karhu-db
```

## Push jobs

```php
use Karhu\Queue\DatabaseQueue;
use Karhu\Db\Connection;

$db = new Connection('sqlite:jobs.db');
$queue = new DatabaseQueue($db);

$queue->push('SendEmail', ['to' => 'bjorn@example.com', 'subject' => 'Hello']);
```

## Process jobs

```php
use Karhu\Queue\Worker;

$worker = new Worker($queue);
$worker->register('SendEmail', function (array $data) {
    mail($data['to'], $data['subject'], 'Body here');
});
$worker->run(); // loops until stopped
```

## Schema

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

`updated_at` tracks the **last status transition** (v0.2.0+). `push()` relies
on the INSERT default; `pop()`, `complete()`, and `fail()` each bump it.
This is what `unstick()` keys off to detect stuck-in-`processing` rows.

`complete()` and `fail()` are guarded by `WHERE id = :id AND status = 'processing'`
so a row that's already been reset (e.g. by `unstick()`) is never silently
flipped to `completed`/`failed` by a stale handler.

## Stuck-job recovery

When a worker is SIGKILL'd mid-job (OOM, host reboot, `docker kill`) the
row stays in `processing` forever — `Worker`'s try/catch only catches handler
exceptions, not no-exception process death. `QueueInterface::unstick()` is
the recovery path. Run it on a cron:

```php
// In your CLI:
$reset = $queue->unstick(300);          // reset rows stuck >5 min
$reset = $queue->unstick(300, 'mail');  // scope to one queue
```

It returns the count of rows reset. The UPDATE's `WHERE` clause
(`status='processing' AND updated_at < cutoff`) IS the dedup — a live worker
that completes a row between the cron's snapshot and the UPDATE will have
flipped `status='completed'`, so the row simply no longer matches. No race
window vs. a completing worker.

**Callers MUST ensure handlers are idempotent.** "Stuck" means "no status
transition in N seconds", NOT "definitely dead". A slow live handler whose
wall time exceeds the threshold WILL be unstuck and re-popped while still
executing. Pick the threshold to safely exceed your slowest handler's wall
time (5× safety factor recommended). Handler idempotency is the load-bearing
contract that makes recovery safe.

## Custom drivers

Implement `QueueInterface` for Redis, RabbitMQ, etc.

## Caveats

`DatabaseQueue::pop()` is **not atomic** — it's a SELECT followed by an UPDATE,
not `SELECT … FOR UPDATE SKIP LOCKED`. Two concurrent workers can pop the
same row. **Use a single worker per queue** until v0.3 lands the atomic claim.
