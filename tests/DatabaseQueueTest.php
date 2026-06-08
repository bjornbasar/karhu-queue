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
}
