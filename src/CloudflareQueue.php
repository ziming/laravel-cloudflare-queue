<?php

namespace OssyCodes\LaravelCloudflareQueue;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Collection;

class CloudflareQueue extends Queue implements QueueContract
{
    /**
     * In-memory buffer of messages pulled in the last batch.
     *
     * Laravel's Worker calls pop() one job at a time. Without a buffer every
     * pop() call would make a separate HTTP request even when batch_size > 1.
     * The buffer lets us pull N messages at once and return them one-by-one.
     *
     * Important: messages in the buffer are already "invisible" to other
     * consumers for visibility_timeout_ms. Set visibility_timeout_ms high
     * enough to cover (batch_size × average job processing time).
     */
    private array $messageBuffer = [];

    public function __construct(
        private readonly CloudflareClient $client,
        private readonly array $config,
    ) {
        $this->dispatchAfterCommit = $this->config['after_commit'] ?? false;
    }

    // -------------------------------------------------------------------------
    // Queue inspection
    // -------------------------------------------------------------------------

    /**
     * Get the total number of messages in the queue.
     */
    public function size($queue = null): int
    {
        $info = $this->client->info();

        return (int) ($info['message_backlog_count'] ?? 0);
    }

    /**
     * Get the number of pending (ready) messages.
     *
     * Cloudflare only exposes a single total backlog count, so we return that.
     */
    public function pendingSize($queue = null): int
    {
        return $this->size($queue);
    }

    /**
     * Cloudflare does not expose delayed message counts separately.
     */
    public function delayedSize($queue = null): int
    {
        return 0;
    }

    /**
     * Cloudflare does not expose reserved (in-flight) message counts separately.
     */
    public function reservedSize($queue = null): int
    {
        return 0;
    }

    /**
     * Cloudflare does not support listing individual pending messages via the REST API.
     */
    public function pendingJobs($queue = null): Collection
    {
        return new Collection();
    }

    /**
     * Cloudflare does not support listing individual delayed messages via the REST API.
     */
    public function delayedJobs($queue = null): Collection
    {
        return new Collection();
    }

    /**
     * Cloudflare does not support listing individual reserved messages via the REST API.
     */
    public function reservedJobs($queue = null): Collection
    {
        return new Collection();
    }

    /**
     * Cloudflare does not support listing individual pending messages via the REST API.
     */
    public function allPendingJobs(): Collection
    {
        return new Collection();
    }

    /**
     * Cloudflare does not support listing individual delayed messages via the REST API.
     */
    public function allDelayedJobs(): Collection
    {
        return new Collection();
    }

    /**
     * Cloudflare does not support listing individual reserved messages via the REST API.
     */
    public function allReservedJobs(): Collection
    {
        return new Collection();
    }

    /**
     * Cloudflare does not expose per-message creation timestamps via the REST API.
     */
    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Pushing jobs
    // -------------------------------------------------------------------------

    /**
     * Push a new job onto the queue.
     */
    public function push($job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            fn ($payload, $queue) => $this->pushRaw($payload, $queue),
        );
    }

    /**
     * Push a raw payload string onto the queue.
     */
    public function pushRaw($payload, $queue = null, array $options = []): ?string
    {
        $this->client->send($payload, (int) ($options['delay'] ?? 0));

        return json_decode($payload, true)['uuid'] ?? null;
    }

    /**
     * Push a new job onto the queue after a delay.
     */
    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data, $delay),
            $queue,
            $delay,
            fn ($payload, $queue, $delay) => $this->pushRaw(
                $payload,
                $queue,
                ['delay' => $this->secondsUntil($delay)]
            ),
        );
    }

    /**
     * Push an array of jobs onto the queue using the batch API when possible.
     */
    public function bulk($jobs, $data = '', $queue = null): void
    {
        if (empty($jobs)) {
            return;
        }

        $payloads = [];

        foreach ((array) $jobs as $job) {
            $payloads[] = $this->createPayload($job, $this->getQueue($queue), $data);
        }

        $this->client->bulkSend($payloads);
    }

    // -------------------------------------------------------------------------
    // Popping jobs
    // -------------------------------------------------------------------------

    /**
     * Pop the next job off the queue.
     *
     * Uses an in-memory message buffer: the first pop() in a cycle pulls a
     * full batch from Cloudflare and stores remaining messages locally.
     * Subsequent pop() calls drain the buffer without hitting the API.
     */
    public function pop($queue = null): ?CloudflareJob
    {
        // Refill buffer when empty
        if (empty($this->messageBuffer)) {
            $this->messageBuffer = $this->client->pull(
                $this->batchSize(),
                $this->visibilityTimeoutMs(),
            );
        }

        if (empty($this->messageBuffer)) {
            return null;
        }

        $message = array_shift($this->messageBuffer);

        return new CloudflareJob(
            $this->container,
            $this->client,
            $message,
            ['raw_handler' => $this->config['raw_handler'] ?? null],
            $this->connectionName,
            $this->getQueue($queue),
        );
    }

    // -------------------------------------------------------------------------
    // Job reservation management
    // -------------------------------------------------------------------------

    /**
     * Delete a reserved job from the queue by acknowledging it.
     */
    public function deleteReserved(string $queue, string $leaseId): void
    {
        $this->client->ack($leaseId);
    }

    /**
     * Delete a reserved job and release it back onto the queue with a delay.
     */
    public function deleteAndRelease(string $queue, CloudflareJob $job, int $delay): void
    {
        $this->client->retry($job->getLeaseId(), max(0, $delay));
    }

    // -------------------------------------------------------------------------
    // Clear
    // -------------------------------------------------------------------------

    /**
     * Cloudflare Queues does not expose a purge endpoint via the consumer REST API.
     *
     * To clear a queue, use the Cloudflare dashboard or Wrangler CLI:
     *   wrangler queues purge <queue-name>
     *
     * @throws \RuntimeException
     */
    public function clear($queue = null): int
    {
        throw new \RuntimeException(
            'Cloudflare Queues does not support clearing the queue via the REST API. '
            . 'Use the Cloudflare dashboard or `wrangler queues purge <queue-name>` instead.'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function getQueue($queue): string
    {
        return $queue ?: ($this->config['queue'] ?? 'default');
    }

    public function getDispatchAfterCommit(): bool
    {
        return $this->dispatchAfterCommit;
    }

    private function batchSize(): int
    {
        return max(1, (int) ($this->config['batch_size'] ?? 1));
    }

    private function visibilityTimeoutMs(): int
    {
        return max(1_000, (int) ($this->config['visibility_timeout_ms'] ?? 30_000));
    }
}
