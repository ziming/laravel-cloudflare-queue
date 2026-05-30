<?php

namespace OssyCodes\LaravelCloudflareQueue;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class CloudflareClient
{
    protected PendingRequest $http;

    public function __construct(private readonly array $config)
    {
        $this->http = $this->buildHttpClient();
    }

    /**
     * Pull a batch of messages from the queue.
     *
     * Returns raw message arrays, each with: id, body, lease_id, attempts.
     */
    public function pull(int $batchSize = 1, int $visibilityTimeoutMs = 30_000): array
    {
        $response = $this->http->post('messages/pull', [
            'batch_size'            => $batchSize,
            'visibility_timeout_ms' => $visibilityTimeoutMs, // correct CF param name
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Cloudflare Queue pull failed [{$response->status()}]: {$response->body()}"
            );
        }

        return $response->json('result.messages', []);
    }

    /**
     * Acknowledge a single message by lease ID, removing it from the queue permanently.
     */
    public function ack(string $leaseId): bool
    {
        return $this->bulkAck([$leaseId]);
    }

    /**
     * Acknowledge multiple messages in one API call.
     */
    public function bulkAck(array $leaseIds): bool
    {
        if (empty($leaseIds)) {
            return true;
        }

        $response = $this->http->post('messages/ack', [
            'acks'    => array_map(fn ($id) => ['lease_id' => $id], $leaseIds),
            'retries' => [],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Cloudflare Queue ack failed [{$response->status()}]: {$response->body()}"
            );
        }

        return (bool) $response->json('success', true);
    }

    /**
     * Release a single message back to the queue with an optional delay.
     */
    public function retry(string $leaseId, int $delaySeconds = 0): bool
    {
        return $this->bulkRetry([
            ['lease_id' => $leaseId, 'delay_seconds' => $delaySeconds],
        ]);
    }

    /**
     * Release multiple messages back to the queue in one API call.
     *
     * Each entry must have 'lease_id' and optionally 'delay_seconds'.
     */
    public function bulkRetry(array $retries): bool
    {
        if (empty($retries)) {
            return true;
        }

        foreach ($retries as $retry) {
            if (! isset($retry['lease_id'])) {
                throw new InvalidArgumentException('Each retry entry must contain a lease_id.');
            }
        }

        $response = $this->http->post('messages/ack', [
            'acks'    => [],
            'retries' => $retries,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Cloudflare Queue retry failed [{$response->status()}]: {$response->body()}"
            );
        }

        return (bool) $response->json('success', true);
    }

    /**
     * Send a single message to the queue.
     */
    public function send(mixed $payload, int $delaySeconds = 0): bool
    {
        $body = [
            'body'         => $payload,
            'content_type' => 'text',
        ];

        if ($delaySeconds > 0) {
            $body['delay_seconds'] = $delaySeconds;
        }

        $response = $this->http->post('messages', $body);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Cloudflare Queue send failed [{$response->status()}]: {$response->body()}"
            );
        }

        return (bool) $response->json('success', true);
    }

    /**
     * Send multiple messages to the queue.
     *
     * Falls back to individual sends if the batch endpoint is unavailable.
     */
    public function bulkSend(array $payloads, int $delaySeconds = 0): bool
    {
        if (empty($payloads)) {
            return true;
        }

        $messages = array_map(function ($payload) use ($delaySeconds) {
            $message = ['body' => $payload, 'content_type' => 'text'];

            if ($delaySeconds > 0) {
                $message['delay_seconds'] = $delaySeconds;
            }

            return $message;
        }, $payloads);

        $response = $this->http->post('messages/batch', ['messages' => $messages]);

        // Fall back to individual sends if batch endpoint is not available
        if ($response->status() === 404) {
            foreach ($payloads as $payload) {
                $this->send($payload, $delaySeconds);
            }

            return true;
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Cloudflare Queue batch send failed [{$response->status()}]: {$response->body()}"
            );
        }

        return (bool) $response->json('success', true);
    }

    /**
     * Fetch queue metadata, including message_backlog_count.
     */
    public function info(): array
    {
        $response = $this->http->get('');

        if (! $response->successful()) {
            return [];
        }

        return $response->json('result', []);
    }

    protected function buildHttpClient(): PendingRequest
    {
        return Http::asJson()
            ->withUserAgent('ossycodes-laravel-cloudflare-queue/1.0')
            ->baseUrl(
                rtrim("https://api.cloudflare.com/client/v4/accounts/{$this->config['account_id']}/queues/{$this->config['queue_id']}", '/').'/'
            )
            ->withToken($this->config['api_token'])
            ->retry(3, 100, throw: false);
    }
}
