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

## Custom drivers

Implement `QueueInterface` for Redis, RabbitMQ, etc.
