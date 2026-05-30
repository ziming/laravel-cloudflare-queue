<?php

use Illuminate\Support\Facades\Http;
use OssyCodes\LaravelCloudflareQueue\CloudflareClient;

beforeEach(function () {
    Http::preventStrayRequests();
});

// -------------------------------------------------------------------------
// pull()
// -------------------------------------------------------------------------

it('pulls messages using correct CF API param name visibility_timeout_ms', function () {
    Http::fake([
        '*/messages/pull' => Http::response([
            'success' => true,
            'result'  => [
                'messages' => [
                    ['id' => 'msg-1', 'body' => 'hello', 'lease_id' => 'lease-1', 'attempts' => 1],
                ],
            ],
        ]),
    ]);

    $messages = $this->makeClient()->pull(batchSize: 5, visibilityTimeoutMs: 60_000);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['id'])->toBe('msg-1');

    Http::assertSent(function ($request) {
        $body = $request->data();
        // Critical: must use visibility_timeout_ms not visibility_timeout
        return $body['batch_size'] === 5
            && $body['visibility_timeout_ms'] === 60_000
            && ! array_key_exists('visibility_timeout', $body);
    });
});

it('returns empty array when no messages are available', function () {
    Http::fake([
        '*/messages/pull' => Http::response([
            'success' => true,
            'result'  => ['messages' => []],
        ]),
    ]);

    $messages = $this->makeClient()->pull();

    expect($messages)->toBeEmpty();
});

it('throws RuntimeException when pull fails', function () {
    Http::fake([
        '*/messages/pull' => Http::response(['success' => false], 403),
    ]);

    expect(fn () => $this->makeClient()->pull())
        ->toThrow(\RuntimeException::class, 'pull failed');
});

// -------------------------------------------------------------------------
// ack()
// -------------------------------------------------------------------------

it('acknowledges a message with correct payload', function () {
    Http::fake([
        '*/messages/ack' => Http::response(['success' => true]),
    ]);

    $result = $this->makeClient()->ack('lease-abc');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();
        return $body['acks'] === [['lease_id' => 'lease-abc']]
            && $body['retries'] === [];
    });
});

it('returns true without hitting the API when acking empty array', function () {
    Http::fake();

    $result = $this->makeClient()->bulkAck([]);

    expect($result)->toBeTrue();
    Http::assertNothingSent();
});

it('throws RuntimeException when ack fails', function () {
    Http::fake([
        '*/messages/ack' => Http::response(['error' => 'lease expired'], 400),
    ]);

    expect(fn () => $this->makeClient()->ack('lease-xyz'))
        ->toThrow(\RuntimeException::class, 'ack failed');
});

// -------------------------------------------------------------------------
// bulkAck()
// -------------------------------------------------------------------------

it('bulk acks multiple messages in a single API call', function () {
    Http::fake([
        '*/messages/ack' => Http::response(['success' => true]),
    ]);

    $result = $this->makeClient()->bulkAck(['lease-1', 'lease-2', 'lease-3']);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        $body  = $request->data();
        $leases = array_column($body['acks'], 'lease_id');
        return count($body['acks']) === 3
            && in_array('lease-1', $leases)
            && in_array('lease-2', $leases)
            && in_array('lease-3', $leases);
    });
});

// -------------------------------------------------------------------------
// retry()
// -------------------------------------------------------------------------

it('retries a message with delay', function () {
    Http::fake([
        '*/messages/ack' => Http::response(['success' => true]),
    ]);

    $result = $this->makeClient()->retry('lease-abc', 30);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();
        return $body['retries'] === [['lease_id' => 'lease-abc', 'delay_seconds' => 30]]
            && $body['acks'] === [];
    });
});

it('throws InvalidArgumentException when retry entry is missing lease_id', function () {
    expect(fn () => $this->makeClient()->bulkRetry([['delay_seconds' => 10]]))
        ->toThrow(\InvalidArgumentException::class, 'lease_id');
});

// -------------------------------------------------------------------------
// send()
// -------------------------------------------------------------------------

it('sends a message to the queue', function () {
    Http::fake([
        '*/messages' => Http::response(['success' => true]),
    ]);

    $result = $this->makeClient()->send('{"email_to":"user@example.com"}');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();
        return $body['body'] === '{"email_to":"user@example.com"}'
            && $body['content_type'] === 'text'
            && ! array_key_exists('delay_seconds', $body);
    });
});

it('includes delay_seconds when delay is specified', function () {
    Http::fake([
        '*/messages' => Http::response(['success' => true]),
    ]);

    $this->makeClient()->send('payload', 60);

    Http::assertSent(function ($request) {
        return $request->data()['delay_seconds'] === 60;
    });
});

it('does not include delay_seconds when delay is zero', function () {
    Http::fake([
        '*/messages' => Http::response(['success' => true]),
    ]);

    $this->makeClient()->send('payload', 0);

    Http::assertSent(function ($request) {
        return ! array_key_exists('delay_seconds', $request->data());
    });
});

it('throws RuntimeException when send fails', function () {
    Http::fake([
        '*/messages' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    expect(fn () => $this->makeClient()->send('payload'))
        ->toThrow(\RuntimeException::class, 'send failed');
});

// -------------------------------------------------------------------------
// bulkSend()
// -------------------------------------------------------------------------

it('sends multiple messages via batch endpoint', function () {
    Http::fake([
        '*/messages/batch' => Http::response(['success' => true]),
    ]);

    $result = $this->makeClient()->bulkSend(['payload-1', 'payload-2']);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return count($request->data()['messages']) === 2;
    });
});

it('falls back to individual sends when batch endpoint returns 404', function () {
    Http::fake([
        '*/messages/batch' => Http::response([], 404),
        '*/messages'       => Http::response(['success' => true]),
    ]);

    $result = $this->makeClient()->bulkSend(['payload-1', 'payload-2']);

    expect($result)->toBeTrue();
    Http::assertSentCount(3); // 1 batch attempt + 2 individual sends
});

it('returns true without hitting the API when bulkSend receives empty array', function () {
    Http::fake();

    $result = $this->makeClient()->bulkSend([]);

    expect($result)->toBeTrue();
    Http::assertNothingSent();
});

// -------------------------------------------------------------------------
// info()
// -------------------------------------------------------------------------

it('returns queue metadata including message_backlog_count', function () {
    Http::fake([
        '*' => Http::response([
            'success' => true,
            'result'  => ['message_backlog_count' => 42, 'queue_name' => 'test-queue'],
        ]),
    ]);

    $info = $this->makeClient()->info();

    expect($info['message_backlog_count'])->toBe(42);
});

it('returns empty array when info request fails', function () {
    Http::fake([
        '*' => Http::response([], 500),
    ]);

    $info = $this->makeClient()->info();

    expect($info)->toBeEmpty();
});
