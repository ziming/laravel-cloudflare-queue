<?php

use Illuminate\Support\Facades\Http;
use OssyCodes\LaravelCloudflareQueue\CloudflareClient;
use OssyCodes\LaravelCloudflareQueue\CloudflareJob;
use OssyCodes\LaravelCloudflareQueue\CloudflareQueue;

// -------------------------------------------------------------------------
// pop() — single message
// -------------------------------------------------------------------------

it('returns null when queue is empty', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('pull')->once()->andReturn([]);

    $queue = $this->makeQueue($client);

    expect($queue->pop())->toBeNull();
});

it('returns a CloudflareJob when a message is available', function () {
    $message = $this->makeMessage();

    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('pull')->once()->andReturn([$message]);

    $result = $this->makeQueue($client)->pop();

    expect($result)->toBeInstanceOf(CloudflareJob::class);
    expect($result->getJobId())->toBe($message['id']);
});

// -------------------------------------------------------------------------
// pop() — message buffer (batch_size > 1)
// -------------------------------------------------------------------------

it('buffers messages and only calls the API once per empty buffer', function () {
    $messages = [
        $this->makeMessage(['id' => 'msg-1']),
        $this->makeMessage(['id' => 'msg-2']),
        $this->makeMessage(['id' => 'msg-3']),
    ];

    $client = Mockery::mock(CloudflareClient::class);
    // API should be called exactly ONCE for three pop() calls
    $client->shouldReceive('pull')->once()->andReturn($messages);

    $queue = $this->makeQueue($client, ['batch_size' => 3]);

    $job1 = $queue->pop();
    $job2 = $queue->pop();
    $job3 = $queue->pop();

    expect($job1->getJobId())->toBe('msg-1');
    expect($job2->getJobId())->toBe('msg-2');
    expect($job3->getJobId())->toBe('msg-3');
});

it('refills the buffer from the API after it is drained', function () {
    $batch1 = [$this->makeMessage(['id' => 'msg-1'])];
    $batch2 = [$this->makeMessage(['id' => 'msg-2'])];

    $client = Mockery::mock(CloudflareClient::class);
    // API called twice: once when buffer is empty initially, once after draining
    $client->shouldReceive('pull')->twice()->andReturn($batch1, $batch2);

    $queue = $this->makeQueue($client, ['batch_size' => 1]);

    $job1 = $queue->pop(); // drains batch1
    $job2 = $queue->pop(); // refills with batch2

    expect($job1->getJobId())->toBe('msg-1');
    expect($job2->getJobId())->toBe('msg-2');
});

it('returns null after buffer is drained and API returns empty', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('pull')->andReturn(
        [$this->makeMessage(['id' => 'msg-1'])], // first call: one message
        []                                        // second call: empty
    );

    $queue = $this->makeQueue($client, ['batch_size' => 1]);

    expect($queue->pop()->getJobId())->toBe('msg-1');
    expect($queue->pop())->toBeNull();
});

// -------------------------------------------------------------------------
// pop() — passes correct config to client
// -------------------------------------------------------------------------

it('passes batch_size and visibility_timeout_ms from config to client pull', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('pull')
        ->once()
        ->with(5, 60_000)
        ->andReturn([]);

    $this->makeQueue($client, [
        'batch_size'            => 5,
        'visibility_timeout_ms' => 60_000,
    ])->pop();
});

it('enforces minimum visibility_timeout_ms of 1000', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('pull')
        ->once()
        ->with(1, 1_000) // should be clamped to 1000
        ->andReturn([]);

    $this->makeQueue($client, ['visibility_timeout_ms' => 0])->pop();
});

it('enforces minimum batch_size of 1', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('pull')
        ->once()
        ->with(1, 30_000) // batch_size clamped to 1
        ->andReturn([]);

    $this->makeQueue($client, ['batch_size' => -5])->pop();
});

// -------------------------------------------------------------------------
// size() and inspection methods
// -------------------------------------------------------------------------

it('returns message_backlog_count from queue info', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('info')->once()->andReturn(['message_backlog_count' => 99]);

    expect($this->makeQueue($client)->size())->toBe(99);
});

it('returns 0 for size when message_backlog_count is missing', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('info')->once()->andReturn([]);

    expect($this->makeQueue($client)->size())->toBe(0);
});

it('pendingSize returns the same value as size', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('info')->twice()->andReturn(['message_backlog_count' => 7]);

    $queue = $this->makeQueue($client);
    expect($queue->pendingSize())->toBe($queue->size());
});

it('delayedSize always returns 0', function () {
    $client = Mockery::mock(CloudflareClient::class);
    expect($this->makeQueue($client)->delayedSize())->toBe(0);
});

it('reservedSize always returns 0', function () {
    $client = Mockery::mock(CloudflareClient::class);
    expect($this->makeQueue($client)->reservedSize())->toBe(0);
});

it('job listing methods return empty collections', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $queue  = $this->makeQueue($client);

    expect($queue->pendingJobs())->toBeInstanceOf(\Illuminate\Support\Collection::class)->toBeEmpty();
    expect($queue->delayedJobs())->toBeEmpty();
    expect($queue->reservedJobs())->toBeEmpty();
    expect($queue->allPendingJobs())->toBeEmpty();
    expect($queue->allDelayedJobs())->toBeEmpty();
    expect($queue->allReservedJobs())->toBeEmpty();
});

it('creationTimeOfOldestPendingJob returns null', function () {
    $client = Mockery::mock(CloudflareClient::class);
    expect($this->makeQueue($client)->creationTimeOfOldestPendingJob())->toBeNull();
});

// -------------------------------------------------------------------------
// push() / pushRaw()
// -------------------------------------------------------------------------

it('pushRaw sends the payload to the client', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('send')->once()->with('{"uuid":"abc"}', 0);

    $this->makeQueue($client)->pushRaw('{"uuid":"abc"}');
});

it('pushRaw passes delay when provided via options', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('send')->once()->with('{"uuid":"abc"}', 30);

    $this->makeQueue($client)->pushRaw('{"uuid":"abc"}', null, ['delay' => 30]);
});

// -------------------------------------------------------------------------
// bulk()
// -------------------------------------------------------------------------

it('bulk push calls client bulkSend', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('bulkSend')->once();

    $this->makeQueue($client)->bulk([
        new \OssyCodes\LaravelCloudflareQueue\Tests\Fixtures\TestJob(),
        new \OssyCodes\LaravelCloudflareQueue\Tests\Fixtures\TestJob(),
    ]);
});

it('bulk push with empty array does not call client', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldNotReceive('bulkSend');

    $this->makeQueue($client)->bulk([]);
});

// -------------------------------------------------------------------------
// deleteReserved() / deleteAndRelease()
// -------------------------------------------------------------------------

it('deleteReserved calls client ack with the lease id', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('ack')->once()->with('lease-xyz');

    $this->makeQueue($client)->deleteReserved('default', 'lease-xyz');
});

it('deleteAndRelease calls client retry with the correct lease id and delay', function () {
    $message = $this->makeMessage(['lease_id' => 'lease-xyz']);

    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('pull')->once()->andReturn([$message]);
    $client->shouldReceive('retry')->once()->with('lease-xyz', 45);

    $queue = $this->makeQueue($client);
    $job   = $queue->pop();

    $queue->deleteAndRelease('default', $job, 45);
});

// -------------------------------------------------------------------------
// clear()
// -------------------------------------------------------------------------

it('throws RuntimeException when clear is called', function () {
    $client = Mockery::mock(CloudflareClient::class);

    expect(fn () => $this->makeQueue($client)->clear())
        ->toThrow(\RuntimeException::class, 'Cloudflare Queues does not support clearing');
});

// -------------------------------------------------------------------------
// dispatchAfterCommit
// -------------------------------------------------------------------------

it('sets dispatchAfterCommit from config', function () {
    $client = Mockery::mock(CloudflareClient::class);

    $queue = $this->makeQueue($client, ['after_commit' => true]);

    expect($queue->getDispatchAfterCommit())->toBeTrue();
});

it('dispatchAfterCommit defaults to false when not configured', function () {
    $client = Mockery::mock(CloudflareClient::class);

    $queue = $this->makeQueue($client, []);

    expect($queue->getDispatchAfterCommit())->toBeFalse();
});

// -------------------------------------------------------------------------
// Connector validation
// -------------------------------------------------------------------------

it('connector throws when account_id is missing', function () {
    $connector = new \OssyCodes\LaravelCloudflareQueue\CloudflareConnector();

    expect(fn () => $connector->connect(['queue_id' => 'q', 'api_token' => 't']))
        ->toThrow(\InvalidArgumentException::class, 'account_id');
});

it('connector throws when queue_id is missing', function () {
    $connector = new \OssyCodes\LaravelCloudflareQueue\CloudflareConnector();

    expect(fn () => $connector->connect(['account_id' => 'a', 'api_token' => 't']))
        ->toThrow(\InvalidArgumentException::class, 'queue_id');
});

it('connector throws when api_token is missing', function () {
    $connector = new \OssyCodes\LaravelCloudflareQueue\CloudflareConnector();

    expect(fn () => $connector->connect(['account_id' => 'a', 'queue_id' => 'q']))
        ->toThrow(\InvalidArgumentException::class, 'api_token');
});

it('connector returns a CloudflareQueue with valid config', function () {
    $connector = new \OssyCodes\LaravelCloudflareQueue\CloudflareConnector();

    $queue = $connector->connect([
        'account_id' => 'a',
        'queue_id'   => 'q',
        'api_token'  => 't',
    ]);

    expect($queue)->toBeInstanceOf(CloudflareQueue::class);
});

afterEach(fn () => Mockery::close());
