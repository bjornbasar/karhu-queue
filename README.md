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

## Custom drivers

Implement `QueueInterface` for Redis, RabbitMQ, etc.
