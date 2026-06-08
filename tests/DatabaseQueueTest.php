<?php

declare(strict_types=1);

namespace Karhu\Queue\Tests;

use Karhu\Db\Connection;
use Karhu\Queue\DatabaseQueue;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the database-backed queue driver.
 *
 * Uses sqlite::memory: so each test is fully isolated (a new PDO and a new
 * schema per setUp). The jobs table mirrors the README schema verbatim;
 * if the README ever changes shape, the fixture here must follow.
 */
final class DatabaseQueueTest extends TestCase
{
    private Connection $db;

    private DatabaseQueue $queue;

    protected function setUp(): void
    {
        // sqlite::memory: gives each test a clean PDO + clean table.
        $this->db = new Connection('sqlite::memory:');
        $this->bootstrapSchema();
        $this->queue = new DatabaseQueue($this->db);
    }

    private function bootstrapSchema(): void
    {
        // Mirrors karhu-queue/README.md exactly; if the README ever diverges,
        // this fixture is the canary that catches it.
        $this->db->run(
            "CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue VARCHAR(50) NOT NULL DEFAULT 'default',
                job VARCHAR(255) NOT NULL,
                data TEXT NOT NULL DEFAULT '{}',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                error TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }

    // ============================================================
    // Commit 1 — pre-fix-green tests
    //
    // These pin the existing push/pop contract before commit 2 changes
    // the pop() / complete() / fail() bodies. If anything below fails
    // against v0.1.0 code, the test is wrong — not the driver.
    // ============================================================

    public function test_push_then_pop_returns_payload(): void
    {
        $this->queue->push('SendEmail', ['to' => 'a@b.test', 'subject' => 'Hi']);

        $item = $this->queue->pop();

        $this->assertNotNull($item);
        $this->assertSame('SendEmail', $item['job']);
        $this->assertSame(['to' => 'a@b.test', 'subject' => 'Hi'], $item['data']);
    }

    public function test_pop_returns_null_when_empty(): void
    {
        $this->assertNull($this->queue->pop());
    }

    public function test_pop_is_scoped_to_named_queue(): void
    {
        $this->queue->push('A', [], 'urgent');
        $this->queue->push('B', [], 'default');

        // Default queue should hand back B, leaving A behind on the urgent queue.
        $item = $this->queue->pop('default');
        $this->assertNotNull($item);
        $this->assertSame('B', $item['job']);

        // Calling default again is now empty…
        $this->assertNull($this->queue->pop('default'));

        // …but A is still on the urgent queue waiting for a pop scoped to it.
        $urgent = $this->queue->pop('urgent');
        $this->assertNotNull($urgent);
        $this->assertSame('A', $urgent['job']);
    }

    // ============================================================
    // Commit 2 — updated_at bumps + status guards on complete/fail
    //
    // Goal: every status transition (push→pending implicit via INSERT default,
    // pending→processing in pop, processing→completed/failed) bumps updated_at
    // so the stuck-job detector in unstick() has a reliable freshness signal.
    // ============================================================

    public function test_pop_bumps_updated_at(): void
    {
        // Backdate the inserted row's updated_at to 1 hour ago so a successful
        // bump is unmistakable in the SELECT below.
        $this->queue->push('SendEmail');
        $oneHourAgo = gmdate('Y-m-d H:i:s\Z', time() - 3600);
        $this->db->run(
            "UPDATE jobs SET updated_at = :ts WHERE id = 1",
            ['ts' => $oneHourAgo],
        );

        $this->queue->pop();

        $row = $this->db->fetchOne("SELECT updated_at FROM jobs WHERE id = 1");
        $this->assertNotNull($row);
        $popped = strtotime((string) $row['updated_at']);
        $this->assertNotFalse($popped, 'updated_at should be a parseable timestamp');
        // ~2s window allows for slow CI without making the assert sloppy.
        $this->assertLessThanOrEqual(2, abs(time() - $popped));
    }

    public function test_complete_transitions_and_bumps_updated_at(): void
    {
        $this->queue->push('SendEmail');
        $item = $this->queue->pop();
        $this->assertNotNull($item);
        // Backdate processing's updated_at so the bump on complete() is visible.
        $oneHourAgo = gmdate('Y-m-d H:i:s\Z', time() - 3600);
        $this->db->run(
            "UPDATE jobs SET updated_at = :ts WHERE id = :id",
            ['ts' => $oneHourAgo, 'id' => $item['id']],
        );

        $this->queue->complete($item['id']);

        $row = $this->db->fetchOne("SELECT status, updated_at FROM jobs WHERE id = :id", ['id' => $item['id']]);
        $this->assertNotNull($row);
        $this->assertSame('completed', $row['status']);
        $bumped = strtotime((string) $row['updated_at']);
        $this->assertNotFalse($bumped);
        $this->assertLessThanOrEqual(2, abs(time() - $bumped));
    }

    public function test_fail_transitions_sets_error_and_bumps_updated_at(): void
    {
        $this->queue->push('SendEmail');
        $item = $this->queue->pop();
        $this->assertNotNull($item);
        $oneHourAgo = gmdate('Y-m-d H:i:s\Z', time() - 3600);
        $this->db->run(
            "UPDATE jobs SET updated_at = :ts WHERE id = :id",
            ['ts' => $oneHourAgo, 'id' => $item['id']],
        );

        $this->queue->fail($item['id'], 'SMTP timeout');

        $row = $this->db->fetchOne("SELECT status, error, updated_at FROM jobs WHERE id = :id", ['id' => $item['id']]);
        $this->assertNotNull($row);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('SMTP timeout', $row['error']);
        $bumped = strtotime((string) $row['updated_at']);
        $this->assertNotFalse($bumped);
        $this->assertLessThanOrEqual(2, abs(time() - $bumped));
    }

    public function test_complete_on_non_processing_row_leaves_state_unchanged(): void
    {
        // Simulates the unstick→re-pop→stale-handler-complete race: cron has
        // reset the row to pending; the original (now-undead) handler calls
        // complete() with the original id. The guard MUST prevent the silent
        // pending→completed flip.
        $this->queue->push('SendEmail');
        // Row sits at status='pending' (never popped).

        $this->queue->complete(1);

        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = 1");
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status'], 'complete() must NOT transition a non-processing row');
    }

    public function test_fail_on_non_processing_row_leaves_state_unchanged(): void
    {
        // Same shape as the complete()-guard test above, for fail().
        $this->queue->push('SendEmail');

        $this->queue->fail(1, 'should not stick');

        $row = $this->db->fetchOne("SELECT status, error FROM jobs WHERE id = 1");
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status'], 'fail() must NOT transition a non-processing row');
        $this->assertNull($row['error']);
    }

    // ============================================================
    // Commit 3 — unstick()
    //
    // The SIGKILL-recovery story. The UPDATE's WHERE clause is the dedup
    // (status='processing' AND updated_at < cutoff) so there's no race
    // window vs. a live worker that completes between snapshot and write —
    // the live worker's complete() would flip status='completed' and the
    // unstick UPDATE simply wouldn't match the row.
    // ============================================================

    /**
     * Insert a job row directly with a controlled updated_at, bypassing push()
     * so we can simulate "stuck for N seconds" deterministically.
     */
    private function insertStuckJob(
        string $status,
        int $secondsAgo,
        string $queue = 'default',
        ?string $error = null,
    ): int {
        $ts = gmdate('Y-m-d H:i:s\Z', time() - $secondsAgo);
        $id = (int) $this->db->insert('jobs', [
            'queue' => $queue,
            'job' => 'StuckJob',
            'data' => '{}',
            'status' => $status,
            'error' => $error,
            'updated_at' => $ts,
        ]);
        return $id;
    }

    public function test_unstick_resets_stuck_rows_to_pending(): void
    {
        $id = $this->insertStuckJob('processing', secondsAgo: 600); // 10 min ago

        $reset = $this->queue->unstick(300); // 5-min threshold

        $this->assertSame(1, $reset);
        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $id]);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
    }

    public function test_unstick_respects_threshold(): void
    {
        // Fresh processing row (10s ago) — must NOT be touched at 300s threshold.
        $fresh = $this->insertStuckJob('processing', secondsAgo: 10);

        $reset = $this->queue->unstick(300);

        $this->assertSame(0, $reset);
        $row = $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $fresh]);
        $this->assertNotNull($row);
        $this->assertSame('processing', $row['status']);
    }

    public function test_unstick_is_idempotent(): void
    {
        // First call resets; the bump on updated_at means second call finds
        // nothing matching < cutoff anymore — even at the same threshold.
        $this->insertStuckJob('processing', secondsAgo: 600);

        $first = $this->queue->unstick(300);
        $second = $this->queue->unstick(300);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
    }

    public function test_unstick_clears_error_and_bumps_updated_at(): void
    {
        // A stuck row may carry a stale error from a previous failed attempt
        // (e.g. unstick used after a fail() that should have been a soft retry).
        // Clear it so the re-popped handler sees a clean slate.
        $id = $this->insertStuckJob('processing', secondsAgo: 600, error: 'stale boom');

        $this->queue->unstick(300);

        $row = $this->db->fetchOne("SELECT status, error, updated_at FROM jobs WHERE id = :id", ['id' => $id]);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['error']);
        $bumped = strtotime((string) $row['updated_at']);
        $this->assertNotFalse($bumped);
        $this->assertLessThanOrEqual(2, abs(time() - $bumped));
    }

    public function test_unstick_scoped_to_queue_ignores_completed_and_failed(): void
    {
        // Mix of statuses + queues; only the 'default' queue's stuck
        // 'processing' row should flip.
        $stuckDefault = $this->insertStuckJob('processing', secondsAgo: 600, queue: 'default');
        $stuckUrgent  = $this->insertStuckJob('processing', secondsAgo: 600, queue: 'urgent');
        $oldCompleted = $this->insertStuckJob('completed', secondsAgo: 600, queue: 'default');
        $oldFailed    = $this->insertStuckJob('failed', secondsAgo: 600, queue: 'default');

        $reset = $this->queue->unstick(300, 'default');

        $this->assertSame(1, $reset, 'only the stuck default row should reset');
        $this->assertSame('pending', $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $stuckDefault])['status']);
        $this->assertSame('processing', $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $stuckUrgent])['status']);
        $this->assertSame('completed', $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $oldCompleted])['status']);
        $this->assertSame('failed', $this->db->fetchOne("SELECT status FROM jobs WHERE id = :id", ['id' => $oldFailed])['status']);
    }

    // ============================================================
    // v0.3 — atomic pop driver-suffix wiring + txn-management branches
    //
    // SQLite in-memory can't simulate a multi-worker race (one PDO per
    // process). These tests pin the load-bearing pieces instead:
    //   - the driver→suffix mapping (the only string PG cares about)
    //   - the two new txn branches (caller-owned vs pop()-owned)
    // Actual SKIP LOCKED semantics are documented PG behaviour; manual
    // two-psql-session verification covers that out-of-band.
    // ============================================================

    public function test_compute_for_update_suffix_returns_pg_clause_for_pgsql(): void
    {
        $method = new \ReflectionMethod(DatabaseQueue::class, 'computeForUpdateSuffix');
        $this->assertSame(' FOR UPDATE SKIP LOCKED', $method->invoke(null, 'pgsql'));
    }

    public function test_compute_for_update_suffix_returns_empty_for_other_drivers(): void
    {
        $method = new \ReflectionMethod(DatabaseQueue::class, 'computeForUpdateSuffix');
        $this->assertSame('', $method->invoke(null, 'sqlite'));
        $this->assertSame('', $method->invoke(null, 'mysql'));
        $this->assertSame('', $method->invoke(null, 'unknown'));
    }

    public function test_pop_respects_caller_opened_transaction(): void
    {
        // When the caller is already in a txn, pop() must NOT commit it —
        // the caller owns the lifecycle. Exercises the $started=false branch.
        $this->queue->push('SendEmail');
        $this->db->pdo()->beginTransaction();

        $item = $this->queue->pop();

        $this->assertTrue($this->db->pdo()->inTransaction(), 'caller-opened txn must still be live');
        $this->assertNotNull($item);
        $this->db->pdo()->commit();
    }

    public function test_pop_on_empty_queue_does_not_leak_transaction(): void
    {
        // Critical correctness: the null-row branch must commit (when pop()
        // opened the txn) so Worker's sleep(5)s doesn't sit on an open
        // snapshot blocking autovacuum / HOT pruning on PG.
        $this->assertFalse($this->db->pdo()->inTransaction(), 'precondition: no inherited txn');

        $item = $this->queue->pop();

        $this->assertNull($item);
        $this->assertFalse($this->db->pdo()->inTransaction(), 'pop() must commit even on empty-queue path');
    }
}
