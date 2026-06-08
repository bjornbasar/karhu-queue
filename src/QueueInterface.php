<?php

declare(strict_types=1);

namespace Karhu\Queue;

/**
 * Queue driver interface.
 *
 * Push jobs onto a queue; workers pull and process them.
 */
interface QueueInterface
{
    /**
     * Push a job onto the queue.
     *
     * @param string               $job  Job class name or identifier
     * @param array<string, mixed> $data Payload data
     * @param string               $queue Queue name (default: 'default')
     */
    public function push(string $job, array $data = [], string $queue = 'default'): void;

    /**
     * Pull the next job from the queue.
     *
     * @return array{id: string|int, job: string, data: array<string, mixed>}|null
     */
    public function pop(string $queue = 'default'): ?array;

    /**
     * Mark a job as completed.
     */
    public function complete(string|int $id): void;

    /**
     * Mark a job as failed.
     */
    public function fail(string|int $id, string $reason = ''): void;

    /**
     * Reset jobs stuck in 'processing' longer than $thresholdSeconds back to 'pending'.
     *
     * Recovers from worker SIGKILL (where the job was claimed but never completed
     * because the process died with no exception). The UPDATE's WHERE clause is
     * the dedup — a live worker that completes a row between this call's transaction
     * and a stale snapshot will already have flipped status='completed', so the
     * row no longer matches the predicate. No race against a completing worker.
     *
     * Implementations MUST bump `updated_at` on the reset rows so they're not
     * immediately re-unstuck on the next cron tick.
     *
     * **CALLERS MUST ENSURE HANDLERS ARE IDEMPOTENT.** "Stuck" here means
     * "no status transition observed in $thresholdSeconds seconds" — NOT
     * "definitely dead". A slow live handler whose wall time exceeds the
     * threshold WILL be unstuck and re-popped while still executing. Pick
     * the threshold to safely exceed your slowest handler's wall time
     * (recommend 5× safety factor). Idempotency at the handler is the
     * load-bearing contract that makes this safe.
     *
     * @param int         $thresholdSeconds Rows older than NOW() - this in 'processing' are reset.
     * @param string|null $queue            If supplied, scope to that queue only. NULL = all queues.
     * @return int Count of rows reset.
     */
    public function unstick(int $thresholdSeconds, ?string $queue = null): int;
}
