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
 *
 * `updated_at` semantics (v0.2.0+): every status transition bumps `updated_at`.
 * pop() flips pending→processing AND bumps; complete() flips processing→completed
 * AND bumps; fail() flips processing→failed AND bumps. Prior to v0.2.0 only
 * INSERT touched `updated_at` so it was effectively a row-creation timestamp.
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

        // Flip pending→processing AND bump updated_at so the stuck-job
        // detector (see unstick()) treats this as a fresh transition.
        // Trailing 'Z' makes the literal explicit UTC for PG TIMESTAMPTZ.
        $now = gmdate('Y-m-d H:i:s\Z');
        $this->db->run(
            "UPDATE {$this->table} SET status = 'processing', updated_at = :now WHERE id = :id",
            ['now' => $now, 'id' => $row['id']],
        );

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
        // status='processing' guard prevents an unstick→re-pop→mid-handler-complete
        // race from silently flipping pending→completed (skipping processing).
        // Library-level safety; single-worker mishka doesn't trigger it today.
        $now = gmdate('Y-m-d H:i:s\Z');
        $this->db->run(
            "UPDATE {$this->table} SET status = 'completed', updated_at = :now
             WHERE id = :id AND status = 'processing'",
            ['now' => $now, 'id' => $id],
        );
    }

    public function fail(string|int $id, string $reason = ''): void
    {
        // Same status guard as complete() — see that method for rationale.
        $now = gmdate('Y-m-d H:i:s\Z');
        $this->db->run(
            "UPDATE {$this->table} SET status = 'failed', error = :error, updated_at = :now
             WHERE id = :id AND status = 'processing'",
            ['error' => $reason, 'now' => $now, 'id' => $id],
        );
    }

    public function unstick(int $thresholdSeconds, ?string $queue = null): int
    {
        // Trailing 'Z' = explicit UTC literal so PG's TIMESTAMPTZ comparison
        // doesn't drift with the session TimeZone setting; SQLite TEXT
        // compares lexicographically on the ISO shape either way.
        $cutoff = gmdate('Y-m-d H:i:s\Z', time() - $thresholdSeconds);
        $now = gmdate('Y-m-d H:i:s\Z');
        $sql = "UPDATE {$this->table}
                SET status = 'pending', error = NULL, updated_at = :now
                WHERE status = 'processing' AND updated_at < :cutoff";
        $params = ['now' => $now, 'cutoff' => $cutoff];
        if ($queue !== null) {
            $sql .= ' AND queue = :queue';
            $params['queue'] = $queue;
        }
        return $this->db->run($sql, $params);
    }
}
