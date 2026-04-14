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
}
