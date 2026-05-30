<?php

use Illuminate\Support\Facades\Http;
use OssyCodes\LaravelCloudflare\Tests\Fixtures\TestJob;
use OssyCodes\LaravelCloudflareQueue\CloudflareJob;
use OssyCodes\LaravelCloudflareQueue\CloudflareQueue;
use OssyCodes\LaravelCloudflareQueue\Tests\Fixtures\RawJobHandler;

beforeEach(fn () => Http::preventStrayRequests());

// -------------------------------------------------------------------------
// Service provider wires up the driver
// -------------------------------------------------------------------------

it('registers the cloudflare queue driver via the service provider', function () {
    $manager = $this->app['queue'];

    // Resolving the cloudflare connection should not throw
    expect(fn () => $manager->connection('cloudflare'))->not->toThrow(\Exception::class);
});

// -------------------------------------------------------------------------
// End-to-end: push → pop cycle
// -------------------------------------------------------------------------

it('push and pop cycle works end-to-end', function () {
    $messageBody = json_encode([
        'uuid'        => 'abc-123',
        'displayName' => \OssyCodes\LaravelCloudflareQueue\Tests\Fixtures\TestJob::class,
        'job'         => 'Illuminate\Queue\CallQueuedHandler@call',
        'data'        => ['commandName' => \OssyCodes\LaravelCloudflareQueue\Tests\Fixtures\TestJob::class, 'command' => ''],
    ]);

    Http::fake([
        // push
        '*/messages'      => Http::response(['success' => true]),
        // pop
        '*/messages/pull' => Http::response([
            'success' => true,
            'result'  => [
                'messages' => [
                    ['id' => 'msg-1', 'body' => $messageBody, 'lease_id' => 'lease-1', 'attempts' => 1],
                ],
            ],
        ]),
        // ack
        '*/messages/ack'  => Http::response(['success' => true]),
    ]);

    $queue = $this->app['queue']->connection('cloudflare');

    // Push
    $queue->pushRaw($messageBody);

    // Pop
    $job = $queue->pop();

    expect($job)->toBeInstanceOf(CloudflareJob::class);
    expect($job->getJobId())->toBe('msg-1');
    expect($job->attempts())->toBe(1);

    // Ack (delete)
    $job->delete();

    Http::assertSentCount(3);
});

// -------------------------------------------------------------------------
// Batch processing: batch_size > 1 makes a single pull call
// -------------------------------------------------------------------------

it('drains a batch of messages with a single pull API call', function () {
    $messages = array_map(
        fn ($i) => ['id' => "msg-{$i}", 'body' => '{}', 'lease_id' => "lease-{$i}", 'attempts' => 1],
        range(1, 5)
    );

    Http::fake([
        '*/messages/pull' => Http::response([
            'success' => true,
            'result'  => ['messages' => $messages],
        ]),
    ]);

    $queue = $this->app['queue']->connection('cloudflare');

    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $job   = $queue->pop();
        $ids[] = $job?->getJobId();
    }

    expect($ids)->toBe(['msg-1', 'msg-2', 'msg-3', 'msg-4', 'msg-5']);

    // Only ONE pull request was made for all 5 pops
    Http::assertSentCount(1);
});

// -------------------------------------------------------------------------
// Raw CF Worker message handling
// -------------------------------------------------------------------------

it('processes a raw cloudflare worker message via raw_handler', function () {
    $rawBody = json_encode(['email_to' => 'user@example.com', 'subject' => 'Hello']);

    Http::fake([
        '*/messages/pull' => Http::response([
            'success' => true,
            'result'  => [
                'messages' => [
                    ['id' => 'msg-raw', 'body' => $rawBody, 'lease_id' => 'lease-raw', 'attempts' => 1],
                ],
            ],
        ]),
    ]);

    // Override config to use raw_handler
    $this->app['config']->set('queue.connections.cloudflare.raw_handler', RawJobHandler::class);

    $job     = $this->app['queue']->connection('cloudflare')->pop();
    $payload = $job->payload();

    expect($payload['displayName'])->toBe(RawJobHandler::class);
    expect($payload['data']['commandName'])->toBe(RawJobHandler::class);

    // Deserialized command should be a RawJobHandler instance with the data
    $command = unserialize($payload['data']['command']);
    expect($command)->toBeInstanceOf(RawJobHandler::class);
    expect($command->data['email_to'])->toBe('user@example.com');
});

// -------------------------------------------------------------------------
// Job release (retry)
// -------------------------------------------------------------------------

it('releases a job back to the queue with a delay', function () {
    Http::fake([
        '*/messages/pull' => Http::response([
            'success' => true,
            'result'  => [
                'messages' => [
                    ['id' => 'msg-1', 'body' => '{}', 'lease_id' => 'lease-1', 'attempts' => 1],
                ],
            ],
        ]),
        '*/messages/ack' => Http::response(['success' => true]),
    ]);

    $job = $this->app['queue']->connection('cloudflare')->pop();
    $job->release(60);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), 'messages/ack')) {
            return false;
        }
        $body = $request->data();
        return isset($body['retries'][0]['lease_id'])
            && $body['retries'][0]['delay_seconds'] === 60;
    });
});

// -------------------------------------------------------------------------
// deleteReserved / deleteAndRelease
// -------------------------------------------------------------------------

it('deleteAndRelease sends the correct lease_id and delay', function () {
    Http::fake([
        '*/messages/pull' => Http::response([
            'success' => true,
            'result'  => [
                'messages' => [
                    ['id' => 'msg-1', 'body' => '{}', 'lease_id' => 'lease-abc', 'attempts' => 1],
                ],
            ],
        ]),
        '*/messages/ack' => Http::response(['success' => true]),
    ]);

    $queue = $this->app['queue']->connection('cloudflare');
    $job   = $queue->pop();

    $queue->deleteAndRelease('default', $job, 120);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), 'messages/ack')) {
            return false;
        }
        $retries = $request->data()['retries'] ?? [];
        return isset($retries[0])
            && $retries[0]['lease_id'] === 'lease-abc'
            && $retries[0]['delay_seconds'] === 120;
    });
});

// -------------------------------------------------------------------------
// Queue size
// -------------------------------------------------------------------------

it('size returns message_backlog_count from the queue info endpoint', function () {
    Http::fake([
        '*' => Http::response([
            'success' => true,
            'result'  => ['message_backlog_count' => 17],
        ]),
    ]);

    $size = $this->app['queue']->connection('cloudflare')->size();

    expect($size)->toBe(17);
});

// -------------------------------------------------------------------------
// Configuration: visibility_timeout_ms is sent correctly
// -------------------------------------------------------------------------

it('sends visibility_timeout_ms not visibility_timeout in pull request', function () {
    Http::fake([
        '*/messages/pull' => Http::response([
            'success' => true,
            'result'  => ['messages' => []],
        ]),
    ]);

    $this->app['config']->set('queue.connections.cloudflare.visibility_timeout_ms', 45_000);
    $this->app['queue']->connection('cloudflare')->pop();

    Http::assertSent(function ($request) {
        $body = $request->data();
        return array_key_exists('visibility_timeout_ms', $body)
            && ! array_key_exists('visibility_timeout', $body)
            && $body['visibility_timeout_ms'] === 45_000;
    });
});
