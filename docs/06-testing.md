# Testing Guide

How to test your application's queue behavior — both the package internals and your own jobs.

---

## Testing Your Own Jobs (Application Level)

### Option 1 — Fake the Queue (No Real Queue Needed)

Laravel provides `Queue::fake()` which intercepts all `::dispatch()` calls and stores them in memory. Nothing gets sent to Cloudflare. This is the recommended approach for feature tests.

```php
use Illuminate\Support\Facades\Queue;
use App\Jobs\SendWelcomeEmail;

test('dispatches welcome email when user registers', function () {
    Queue::fake();

    $response = $this->post('/register', [
        'name'     => 'John',
        'email'    => 'john@example.com',
        'password' => 'password',
    ]);

    $response->assertRedirect('/dashboard');

    // Assert the job was dispatched
    Queue::assertPushed(SendWelcomeEmail::class);

    // Assert it was dispatched for the right user
    Queue::assertPushed(SendWelcomeEmail::class, function ($job) {
        return $job->user->email === 'john@example.com';
    });

    // Assert it was dispatched exactly once
    Queue::assertPushedTimes(SendWelcomeEmail::class, 1);

    // Assert nothing else was dispatched
    Queue::assertNotPushed(SomethingElse::class);
});
```

`Queue::fake()` replaces the queue manager with a fake implementation. All connections (cloudflare, redis, database) are intercepted — no HTTP calls are made.

### Option 2 — Test the Job Class Directly

Test `handle()` in isolation without involving the queue at all:

```php
use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('sends welcome email to the correct address', function () {
    Mail::fake();

    $user = User::factory()->create(['email' => 'john@example.com']);

    // Call handle() directly — no queue involved
    (new SendWelcomeEmail($user))->handle();

    Mail::assertSent(WelcomeEmail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});
```

### Option 3 — Run Job Synchronously in Tests

Use `dispatchSync()` to run the job immediately without queuing:

```php
// In your test
SendWelcomeEmail::dispatchSync($user);

// Or set QUEUE_CONNECTION=sync in your .env.testing
// Every dispatch() becomes immediate and synchronous
```

Or in `phpunit.xml`:
```xml
<env name="QUEUE_CONNECTION" value="sync"/>
```

---

## Testing the Package Internals

The package tests use **Pest PHP** with mocked HTTP calls via `Http::fake()`.

### Running the Tests

```bash
# Install dependencies first
cd /path/to/laravel-cloudflare-queue
composer install

# Run all tests
./vendor/bin/pest

# Run with coverage report
./vendor/bin/pest --coverage

# Run only unit tests
./vendor/bin/pest --testsuite=Unit

# Run only feature tests
./vendor/bin/pest --testsuite=Feature

# Run a specific test file
./vendor/bin/pest tests/Unit/CloudflareClientTest.php

# Run tests matching a name
./vendor/bin/pest --filter="visibility_timeout_ms"
```

### How the Tests Work

#### Mocking HTTP Calls (Unit Tests)

`CloudflareClientTest` uses `Http::fake()` to intercept all outgoing HTTP requests:

```php
it('pulls messages using correct CF API param name visibility_timeout_ms', function () {
    Http::fake([
        '*/messages/pull' => Http::response([
            'success' => true,
            'result'  => ['messages' => [
                ['id' => 'msg-1', 'body' => 'hello', 'lease_id' => 'lease-1', 'attempts' => 1],
            ]],
        ]),
    ]);

    $messages = $this->makeClient()->pull(batchSize: 5, visibilityTimeoutMs: 60_000);

    // Assert the correct parameter name was sent
    Http::assertSent(function ($request) {
        $body = $request->data();
        return $body['visibility_timeout_ms'] === 60_000
            && ! array_key_exists('visibility_timeout', $body); // NOT the wrong name
    });
});
```

`Http::preventStrayRequests()` in `beforeEach` makes any unmatched HTTP request throw an exception — preventing accidental real API calls during tests.

#### Mocking the Client (Queue Tests)

`CloudflareQueueTest` mocks `CloudflareClient` using Mockery so tests don't need HTTP at all:

```php
it('buffers messages and only calls the API once per empty buffer', function () {
    $messages = [
        $this->makeMessage(['id' => 'msg-1']),
        $this->makeMessage(['id' => 'msg-2']),
        $this->makeMessage(['id' => 'msg-3']),
    ];

    $client = Mockery::mock(CloudflareClient::class);
    // This expectation FAILS if pull() is called more than once
    $client->shouldReceive('pull')->once()->andReturn($messages);

    $queue = $this->makeQueue($client, ['batch_size' => 3]);

    // Three pops — should only trigger ONE API call
    $queue->pop();
    $queue->pop();
    $queue->pop();
});
```

#### Feature Tests (End-to-End Through Service Provider)

`CloudflareQueueFeatureTest` tests the entire stack — from the service provider down to HTTP calls:

```php
it('push and pop cycle works end-to-end', function () {
    Http::fake([
        '*/messages'      => Http::response(['success' => true]),
        '*/messages/pull' => Http::response([...]),
        '*/messages/ack'  => Http::response(['success' => true]),
    ]);

    // Use the real connection (goes through service provider + connector)
    $queue = $this->app['queue']->connection('cloudflare');

    $queue->pushRaw($messageBody);
    $job = $queue->pop();
    $job->delete();

    Http::assertSentCount(3); // push + pull + ack
});
```

---

## Writing Tests for Jobs That Use This Driver

### Test That Jobs Are Dispatched Correctly

```php
use Illuminate\Support\Facades\Queue;

test('order controller dispatches fulfillment jobs', function () {
    Queue::fake();

    $this->post('/orders', $orderData)->assertCreated();

    Queue::assertPushed(ProcessPayment::class);
    Queue::assertPushed(SendOrderConfirmation::class);
    Queue::assertPushed(UpdateInventory::class);
    Queue::assertPushedTimes(ProcessPayment::class, 1);
});
```

### Test That a Delayed Job Has the Right Delay

```php
Queue::fake();

SendReminder::dispatch($user)->delay(now()->addHours(24));

Queue::assertPushed(SendReminder::class, function ($job) {
    // The job's delay property should be set
    return $job->delay >= now()->addHours(23)->timestamp;
});
```

### Test Job Failure Handling

```php
test('failed job is logged and user is notified', function () {
    // Make the job fail by mocking the mail driver to throw
    Mail::shouldReceive('to')->andThrow(new \Exception('Mail server down'));

    $user = User::factory()->create();
    $job = new SendWelcomeEmail($user);

    // Simulate the job failing
    $job->failed(new \Exception('Mail server down'));

    // Assert your failed() method did the right thing
    // (e.g., logged the error, sent a Slack notification, etc.)
    Log::assertLogged('error', function ($message) use ($user) {
        return str_contains($message, "failed for user {$user->id}");
    });
});
```

### Integration Test with Real Cloudflare (Caution)

If you want to test against the real Cloudflare API, use a dedicated test queue and guard it behind an environment check:

```php
test('can push and pull from real cloudflare queue', function () {
    if (! env('CLOUDFLARE_RUN_INTEGRATION_TESTS')) {
        $this->markTestSkipped('Integration tests disabled');
    }

    $queue = app('queue')->connection('cloudflare');

    // Push a test message
    $queue->pushRaw(json_encode(['test' => true, 'uuid' => (string) Str::uuid()]));

    // Wait a moment for propagation
    sleep(1);

    // Pull it back
    $job = $queue->pop();

    expect($job)->not->toBeNull();
    expect(json_decode($job->getRawBody(), true)['test'])->toBeTrue();

    // Clean up — ack the message
    $job->delete();
})->group('integration');
```

Run integration tests with:
```bash
CLOUDFLARE_RUN_INTEGRATION_TESTS=true ./vendor/bin/pest --group=integration
```

---

## Test Helpers in `TestCase`

The `tests/TestCase.php` provides helper methods you can use in your tests:

```php
// Create a CloudflareClient instance (real, uses test credentials)
$client = $this->makeClient(['api_token' => 'override-token']);

// Create a CloudflareQueue instance with a mocked client
$client = Mockery::mock(CloudflareClient::class);
$queue  = $this->makeQueue($client, ['batch_size' => 5]);

// Create a fake CF message array
$message = $this->makeMessage([
    'id'       => 'custom-id',
    'body'     => '{"my":"data"}',
    'attempts' => 3,
]);
```
