<?php

declare(strict_types=1);

namespace Karhu\Queue;

/**
 * Queue worker — pulls and processes jobs.
 *
 * Usage:
 *   $worker = new Worker($queue);
 *   $worker->register('SendEmail', fn($data) => mail($data['to'], ...));
 *   $worker->run(); // loops until stopped
 */
final class Worker
{
    /** @var array<string, callable(array<string, mixed>): void> */
    private array $handlers = [];

    private bool $running = false;

    public function __construct(
        private readonly QueueInterface $queue,
        private readonly string $queueName = 'default',
        private readonly int $sleepSeconds = 5,
    ) {}

    /**
     * Register a handler for a job type.
     *
     * @param callable(array<string, mixed>): void $handler
     */
    public function register(string $job, callable $handler): void
    {
        $this->handlers[$job] = $handler;
    }

    /** Run the worker loop. Call stop() to break out. */
    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            $processed = $this->processNext();

            if (!$processed) {
                sleep($this->sleepSeconds);
            }
        }
    }

    /** Process the next job in the queue. Returns true if a job was processed. */
    public function processNext(): bool
    {
        $item = $this->queue->pop($this->queueName);

        if ($item === null) {
            return false;
        }

        $handler = $this->handlers[$item['job']] ?? null;

        if ($handler === null) {
            $this->queue->fail($item['id'], "No handler registered for job: {$item['job']}");
            return true;
        }

        try {
            $handler($item['data']);
            $this->queue->complete($item['id']);
        } catch (\Throwable $e) {
            $this->queue->fail($item['id'], $e->getMessage());
        }

        return true;
    }

    /** Stop the worker loop after the current job finishes. */
    public function stop(): void
    {
        $this->running = false;
    }
}
