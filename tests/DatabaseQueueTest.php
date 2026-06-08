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
}
