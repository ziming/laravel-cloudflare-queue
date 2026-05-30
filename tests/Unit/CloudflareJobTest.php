<?php

use OssyCodes\LaravelCloudflareQueue\CloudflareClient;
use OssyCodes\LaravelCloudflareQueue\CloudflareJob;
use OssyCodes\LaravelCloudflareQueue\Tests\Fixtures\RawJobHandler;

function makeJob(array $messageOverrides = [], array $config = []): CloudflareJob
{
    $client = Mockery::mock(CloudflareClient::class);

    return new CloudflareJob(
        app(),
        $client,
        array_merge([
            'id'       => 'msg-123',
            'body'     => json_encode(['uuid' => 'test-uuid', 'job' => 'SomeJob']),
            'lease_id' => 'lease-abc',
            'attempts' => 2,
        ], $messageOverrides),
        array_merge(['raw_handler' => null], $config),
        'cloudflare',
        'default',
    );
}

// -------------------------------------------------------------------------
// Basic getters
// -------------------------------------------------------------------------

it('returns the correct job id', function () {
    expect(makeJob()->getJobId())->toBe('msg-123');
});

it('returns the correct attempt count', function () {
    expect(makeJob()->attempts())->toBe(2);
});

it('defaults attempts to 1 when key is missing', function () {
    expect(makeJob(['attempts' => null])->attempts())->toBe(1);
});

it('returns the lease id', function () {
    expect(makeJob()->getLeaseId())->toBe('lease-abc');
});

it('returns the raw message array', function () {
    $job = makeJob();
    expect($job->getMessage()['id'])->toBe('msg-123');
});

// -------------------------------------------------------------------------
// getRawBody()
// -------------------------------------------------------------------------

it('returns the body as-is when no raw_handler is configured', function () {
    $body = json_encode(['uuid' => 'abc', 'job' => 'MyJob']);
    $job  = makeJob(['body' => $body]);

    expect($job->getRawBody())->toBe($body);
});

it('wraps the body with a uuid when raw_handler is configured', function () {
    $job = makeJob(
        ['body' => json_encode(['email_to' => 'user@example.com'])],
        ['raw_handler' => RawJobHandler::class]
    );

    $raw     = json_decode($job->getRawBody(), true);
    expect($raw)->toHaveKey('uuid');
    expect($raw['email_to'])->toBe('user@example.com');
});

it('handles non-json body gracefully when raw_handler is configured', function () {
    // A CF Worker might push a plain string body, not JSON
    $job = makeJob(
        ['body' => 'plain-string-body'],
        ['raw_handler' => RawJobHandler::class]
    );

    $raw = json_decode($job->getRawBody(), true);

    // Should not crash — should decode to an array with a uuid
    expect($raw)->toBeArray();
    expect($raw)->toHaveKey('uuid');
});

it('generates a unique uuid for each getRawBody call', function () {
    $job1 = makeJob(['body' => '{}'], ['raw_handler' => RawJobHandler::class]);
    $job2 = makeJob(['body' => '{}'], ['raw_handler' => RawJobHandler::class]);

    $uuid1 = json_decode($job1->getRawBody(), true)['uuid'];
    $uuid2 = json_decode($job2->getRawBody(), true)['uuid'];

    expect($uuid1)->not->toBe($uuid2);
});

// -------------------------------------------------------------------------
// payload() for raw_handler
// -------------------------------------------------------------------------

it('builds a synthetic laravel job payload for raw_handler messages', function () {
    $job = makeJob(
        ['body' => json_encode(['food' => 'lemons'])],
        ['raw_handler' => RawJobHandler::class]
    );

    $payload = $job->payload();

    expect($payload['displayName'])->toBe(RawJobHandler::class);
    expect($payload['job'])->toBe('Illuminate\Queue\CallQueuedHandler@call');
    expect($payload['data']['commandName'])->toBe(RawJobHandler::class);
    expect($payload['data']['command'])->toBeString(); // serialized object
});

it('returns parent payload when no raw_handler is configured', function () {
    $body = json_encode([
        'uuid'        => 'abc-123',
        'displayName' => 'SomeJob',
        'job'         => 'Illuminate\Queue\CallQueuedHandler@call',
        'data'        => ['commandName' => 'SomeJob', 'command' => ''],
    ]);

    $job     = makeJob(['body' => $body]);
    $payload = $job->payload();

    expect($payload['uuid'])->toBe('abc-123');
    expect($payload['displayName'])->toBe('SomeJob');
});

// -------------------------------------------------------------------------
// delete() and release()
// -------------------------------------------------------------------------

it('calls client->ack on delete', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('ack')->once()->with('lease-abc');

    $job = new CloudflareJob(
        app(), $client,
        ['id' => 'msg-1', 'body' => '{}', 'lease_id' => 'lease-abc', 'attempts' => 1],
        ['raw_handler' => null],
        'cloudflare', 'default'
    );

    $job->delete();
});

it('calls client->retry on release with the given delay', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('retry')->once()->with('lease-abc', 30);

    $job = new CloudflareJob(
        app(), $client,
        ['id' => 'msg-1', 'body' => '{}', 'lease_id' => 'lease-abc', 'attempts' => 1],
        ['raw_handler' => null],
        'cloudflare', 'default'
    );

    $job->release(30);
});

it('calls retry with 0 delay when release is called with no argument', function () {
    $client = Mockery::mock(CloudflareClient::class);
    $client->shouldReceive('retry')->once()->with('lease-abc', 0);

    $job = new CloudflareJob(
        app(), $client,
        ['id' => 'msg-1', 'body' => '{}', 'lease_id' => 'lease-abc', 'attempts' => 1],
        ['raw_handler' => null],
        'cloudflare', 'default'
    );

    $job->release(0);
});

afterEach(fn () => Mockery::close());
