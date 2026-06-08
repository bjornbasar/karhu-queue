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
    /**
     * Suffix appended to the pop()-SELECT. ' FOR UPDATE SKIP LOCKED' on PG,
     * '' on every other driver. Resolved once at ctor time so per-call cost
     * is zero. See computeForUpdateSuffix() for the mapping rationale.
     */
    private readonly string $forUpdateSuffix;

    public function __construct(
        private readonly Connection $db,
        private readonly string $table = 'jobs',
    ) {
        $driver = (string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->forUpdateSuffix = self::computeForUpdateSuffix($driver);
    }

    /**
     * Driver→suffix lookup. PG 9.5+ supports FOR UPDATE SKIP LOCKED for
     * race-free claim. SQLite has no FOR UPDATE syntax (would be a syntax
     * error) and is single-writer at the engine level anyway. MySQL 8.0+
     * supports it but v0.3 ships no MySQL test surface — falls back to the
     * v0.2 SELECT-then-UPDATE shape with the documented "single worker per
     * queue" caveat.
     *
     * Extracted as a static so tests can pin the mapping directly without
     * constructing a real PDO/Connection.
     *
     * LEADING SPACE in the PG return value is load-bearing: it joins
     * cleanly to "LIMIT 1" in the SELECT interpolation without leaving
     * a trailing space on non-PG drivers (where the suffix is ''). Do
     * not strip.
     */
    private static function computeForUpdateSuffix(string $driver): string
    {
        return $driver === 'pgsql' ? ' FOR UPDATE SKIP LOCKED' : '';
    }

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
        // $pdo and $this->db share the same underlying PDO (Connection holds
        // a single instance), so txn state flows transparently across the
        // helper fetchOne/run calls below.
        $pdo = $this->db->pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        try {
            // Clause order is load-bearing: LIMIT 1 must precede FOR UPDATE
            // SKIP LOCKED. PG 9.5+ applies row locks AFTER LIMIT, so this
            // locks exactly the row we'll claim (not the head of the scan).
            // Reordering would either be a syntax error or lock-and-discard
            // rows we don't claim.
            $row = $this->db->fetchOne(
                "SELECT * FROM {$this->table}
                 WHERE queue = :queue AND status = 'pending'
                 ORDER BY id ASC
                 LIMIT 1{$this->forUpdateSuffix}",
                ['queue' => $queue],
            );

            if ($row === null) {
                // CRITICAL: commit on the null-row branch too. Without this,
                // empty-queue ticks leak open txns; Worker then sleep(5)s
                // with a live snapshot, blocking autovacuum on PG.
                if ($started) {
                    $pdo->commit();
                }
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

            if ($started) {
                $pdo->commit();
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $row['data'], true) ?? [];

            return [
                'id' => $row['id'],
                'job' => (string) $row['job'],
                'data' => $decoded,
            ];
        } catch (\Throwable $e) {
            // inTransaction() guard against double-rollBack when PDO has
            // already auto-rolled on an inner error (defensive; cheap).
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
