<?php

declare(strict_types=1);

namespace Karhu\Queue;

use Karhu\Db\Connection;

/**
 * Database-backed queue driver using karhu-db Connection.
 *
 * Expects a `jobs` table:
 *
 *     CREATE TABLE jobs (
 *         id INTEGER PRIMARY KEY AUTOINCREMENT,
 *         queue VARCHAR(50) NOT NULL DEFAULT 'default',
 *         job VARCHAR(255) NOT NULL,
 *         data TEXT NOT NULL DEFAULT '{}',
 *         status VARCHAR(20) NOT NULL DEFAULT 'pending',
 *         error TEXT,
 *         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *         updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 *     );
 */
final class DatabaseQueue implements QueueInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $table = 'jobs',
    ) {}

    public function push(string $job, array $data = [], string $queue = 'default'): void
    {
        $this->db->insert($this->table, [
            'queue' => $queue,
            'job' => $job,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'status' => 'pending',
        ]);
    }

    public function pop(string $queue = 'default'): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE queue = :queue AND status = 'pending' ORDER BY id ASC LIMIT 1",
            ['queue' => $queue],
        );

        if ($row === null) {
            return null;
        }

        // Mark as processing
        $this->db->update($this->table, ['status' => 'processing'], ['id' => $row['id']]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $row['data'], true) ?? [];

        return [
            'id' => $row['id'],
            'job' => (string) $row['job'],
            'data' => $decoded,
        ];
    }

    public function complete(string|int $id): void
    {
        $this->db->update($this->table, ['status' => 'completed'], ['id' => $id]);
    }

    public function fail(string|int $id, string $reason = ''): void
    {
        $this->db->update($this->table, ['status' => 'failed', 'error' => $reason], ['id' => $id]);
    }
}
