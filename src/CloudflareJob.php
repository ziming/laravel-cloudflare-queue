<?php

namespace OssyCodes\LaravelCloudflareQueue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Str;

class CloudflareJob extends Job implements JobContract
{
    public function __construct(
        Container $container,
        protected readonly CloudflareClient $client,
        protected readonly array $message,
        protected readonly array $config,
        string $connectionName,
        protected readonly string $queueName,
    ) {
        $this->container      = $container;
        $this->connectionName = $connectionName;
        $this->queue          = $queueName;
    }

    /**
     * Acknowledge the job — removes it from the queue permanently.
     */
    public function delete(): void
    {
        parent::delete();

        $this->client->ack($this->message['lease_id']);
    }

    /**
     * Release the job back onto the queue after a delay.
     */
    public function release($delay = 0): void
    {
        parent::release($delay);

        $this->client->retry($this->message['lease_id'], max(0, (int) $delay));
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return $this->message['id'];
    }

    /**
     * Get the number of times this job has been attempted.
     */
    public function attempts(): int
    {
        return (int) ($this->message['attempts'] ?? 1);
    }

    /**
     * Get the raw body string for the job.
     *
     * When a raw_handler is configured (i.e. the message was pushed by a
     * Cloudflare Worker, not by Laravel itself), we wrap the raw payload so
     * Laravel's CallQueuedHandler can dispatch it to the correct class.
     */
    public function getRawBody(): string
    {
        if (! $this->config['raw_handler']) {
            return $this->message['body'];
        }

        // Safely decode — body may be a plain string from a CF Worker
        $data = json_decode($this->message['body'], associative: true) ?? [];

        // Inject a UUID so Laravel's job pipeline can track it
        $data['uuid'] = (string) Str::uuid();

        return json_encode($data);
    }

    /**
     * Get the decoded payload for the job.
     *
     * For raw CF Worker messages, builds a synthetic Laravel job payload that
     * routes to the configured raw_handler class.
     */
    public function payload(): array
    {
        if (! $this->config['raw_handler']) {
            return parent::payload();
        }

        $handler = $this->config['raw_handler'];

        $data = json_decode($this->message['body'], associative: true) ?? [];

        return [
            'id'          => $this->message['id'],
            'uuid'        => (string) Str::uuid(),
            'displayName' => $handler,
            'job'         => 'Illuminate\Queue\CallQueuedHandler@call',
            'data'        => [
                'commandName' => $handler,
                'command'     => serialize(new $handler($data)),
            ],
        ];
    }

    /**
     * Get the underlying raw CF message array.
     */
    public function getMessage(): array
    {
        return $this->message;
    }

    /**
     * Get the lease ID for this message.
     */
    public function getLeaseId(): string
    {
        return $this->message['lease_id'];
    }
}
