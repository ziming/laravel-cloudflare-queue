# Package Internals — How Every File Works

This document walks through every file in the `src/` directory, explains what it does, why it exists, and how it connects to the other files.

---

## File Map

```
src/
├── CloudflareQueueServiceProvider.php   ← registers the driver with Laravel
├── CloudflareConnector.php              ← creates the queue instance from config
├── CloudflareClient.php                 ← makes HTTP calls to Cloudflare API
├── CloudflareQueue.php                  ← implements Laravel's Queue contract
└── CloudflareJob.php                    ← implements Laravel's Job contract
```

These five files form a chain:

```
Laravel's QueueManager
    → CloudflareQueueServiceProvider  (tells Laravel the driver exists)
        → CloudflareConnector         (creates the queue from config)
            → CloudflareQueue         (push/pop logic + buffer)
                → CloudflareClient    (HTTP calls to Cloudflare)
                → CloudflareJob       (returned by pop, handles ack/retry)
```

---

## 1. `CloudflareQueueServiceProvider.php`

### What it is

A Laravel Service Provider. Service Providers are the way packages plug into Laravel's application container. Think of them as "registration hooks" that run when your app boots.

### What it does

```php
class CloudflareQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving('queue', function ($manager) {
            $manager->addConnector('cloudflare', fn () => new CloudflareConnector());
        });
    }
}
```

Line by line:

- `$this->app->afterResolving('queue', ...)` — Run this callback after the `QueueManager` is first resolved from the container. This is the hook point for registering queue drivers.
- `$manager->addConnector('cloudflare', ...)` — Register the string `'cloudflare'` as a known driver name. When Laravel sees `driver => cloudflare` in your queue config, it uses this registration.
- `fn () => new CloudflareConnector()` — A factory that creates the connector. Laravel calls this when it first needs to connect to the cloudflare queue.

### How it's discovered

In `composer.json`:
```json
"extra": {
    "laravel": {
        "providers": ["OssyCodes\\LaravelCloudflareQueue\\CloudflareQueueServiceProvider"]
    }
}
```

When you run `composer install`, Laravel reads this and automatically adds the provider to its bootstrap list. You don't need to manually add it to `config/app.php`.

### Flow

```
App boots
  → Service providers load
  → CloudflareQueueServiceProvider::register() runs
  → 'cloudflare' driver is registered in QueueManager
  
Later, when queue:work starts:
  → QueueManager::connection('cloudflare')
  → looks up connector for 'cloudflare'
  → calls CloudflareConnector factory
  → returns a connected CloudflareQueue instance
```

---

## 2. `CloudflareConnector.php`

### What it is

A Connector is a factory that reads your queue config and produces a `CloudflareQueue` instance ready for use. It's the bridge between "config array" and "live queue object."

### What it does

```php
class CloudflareConnector implements ConnectorInterface
{
    public function connect(array $config): CloudflareQueue
    {
        // Validate required config keys first
        foreach (['account_id', 'queue_id', 'api_token'] as $required) {
            if (empty($config[$required])) {
                throw new InvalidArgumentException(
                    "Cloudflare Queue config is missing required key: [{$required}]."
                );
            }
        }

        // Build the client (HTTP layer) and the queue (logic layer)
        return new CloudflareQueue(new CloudflareClient($config), $config);
    }
}
```

The `$config` array is whatever you put in `config/queue.php` under your cloudflare connection:
```php
'cloudflare' => [
    'driver'               => 'cloudflare',  // ← Laravel removes this, it's used for routing
    'account_id'           => env('CLOUDFLARE_ACCOUNT_ID'),
    'queue_id'             => env('CLOUDFLARE_QUEUE_ID'),
    'api_token'            => env('CLOUDFLARE_API_TOKEN'),
    'batch_size'           => 10,
    'visibility_timeout_ms' => 30_000,
    'after_commit'         => false,
    'raw_handler'          => null,
]
```

### Why Validation Here?

Validation happens in the connector rather than in the client or queue because the connector is the entry point from config. If you have a typo in `.env`, you get a clear error message immediately when the queue connection is first used — not a mysterious HTTP 401 from Cloudflare.

---

## 3. `CloudflareClient.php`

### What it is

A thin HTTP client that wraps all Cloudflare Queue REST API calls. It knows nothing about Laravel jobs, serialization, or the worker loop. It just speaks HTTP to Cloudflare.

### Why a Separate Client?

Separating the HTTP layer from the queue logic has several benefits:
1. **Testability** — Tests can mock `CloudflareClient` without making real HTTP requests
2. **Single responsibility** — `CloudflareQueue` doesn't need to know about HTTP headers, retries, or base URLs
3. **Reusability** — If you need to interact with the Cloudflare Queue API from somewhere else (e.g., an artisan command), you can use `CloudflareClient` directly

### Methods and Their API Endpoints

```
CloudflareClient method          Cloudflare API endpoint
─────────────────────────────    ───────────────────────────────────────
pull(batchSize, timeoutMs)   →   POST /messages/pull
ack(leaseId)                 →   POST /messages/ack  { acks: [...] }
bulkAck(leaseIds[])          →   POST /messages/ack  { acks: [...] }
retry(leaseId, delay)        →   POST /messages/ack  { retries: [...] }
bulkRetry(retries[])         →   POST /messages/ack  { retries: [...] }
send(payload, delay)         →   POST /messages
bulkSend(payloads[], delay)  →   POST /messages/batch  (fallback: individual sends)
info()                       →   GET  /              (queue metadata)
```

Note: ACK and retry both use `POST /messages/ack` — that single endpoint handles both acknowledgements and retries depending on which array you populate (`acks` vs `retries`).

### The HTTP Client Setup

```php
protected function buildHttpClient(): PendingRequest
{
    return Http::asJson()
        ->withUserAgent('ossycodes-laravel-cloudflare-queue/1.0')
        ->baseUrl(
            "https://api.cloudflare.com/client/v4/accounts/{$this->config['account_id']}/queues/{$this->config['queue_id']}/"
        )
        ->withToken($this->config['api_token'])  // adds Authorization: Bearer {token}
        ->retry(3, 100, throw: false);           // retry up to 3 times, 100ms apart
}
```

`Http::asJson()` — Automatically sets `Content-Type: application/json` and JSON-encodes request bodies.

`->baseUrl(...)` — All subsequent calls like `$this->http->post('messages/pull', ...)` are relative to this base. So `post('messages/pull')` becomes `POST https://api.cloudflare.com/client/v4/accounts/{id}/queues/{id}/messages/pull`.

`->retry(3, 100, throw: false)` — If a request fails due to a transient network error, retry up to 3 times with 100ms between attempts. The `throw: false` means it won't throw on retry failures (we handle that with our own status checks).

### Critical Fix: `visibility_timeout_ms`

```php
public function pull(int $batchSize = 1, int $visibilityTimeoutMs = 30_000): array
{
    $response = $this->http->post('messages/pull', [
        'batch_size'            => $batchSize,
        'visibility_timeout_ms' => $visibilityTimeoutMs, // ← CORRECT param name
    ]);
    ...
}
```

The existing community package used `visibility_timeout` (wrong). Cloudflare's API requires `visibility_timeout_ms`. With the wrong name, Cloudflare silently ignores the parameter and uses its default timeout, which could be very short — causing messages to reappear for re-processing while still being worked on.

### BulkSend with Fallback

```php
public function bulkSend(array $payloads, int $delaySeconds = 0): bool
{
    // Try the batch endpoint first
    $response = $this->http->post('messages/batch', ['messages' => $messages]);

    // If it doesn't exist (404), fall back to individual sends
    if ($response->status() === 404) {
        foreach ($payloads as $payload) {
            $this->send($payload, $delaySeconds);
        }
        return true;
    }
    ...
}
```

The Cloudflare Queue batch send endpoint may or may not be available depending on the API version. The fallback ensures `bulk()` always works even if the batch endpoint is unavailable.

---

## 4. `CloudflareQueue.php`

### What it is

The main queue class. This is what Laravel's Worker interacts with. It implements the `QueueContract` interface, which means Laravel can use it interchangeably with `DatabaseQueue`, `RedisQueue`, etc.

### The In-Memory Message Buffer

```php
private array $messageBuffer = [];
```

This is the most important design decision in the queue class. Without it, every `pop()` call makes a separate HTTP request to Cloudflare — even when `batch_size` is 10, you'd be pulling one message at a time.

**How the buffer works:**

```php
public function pop($queue = null): ?CloudflareJob
{
    // Step 1: If the buffer is empty, fetch a new batch from Cloudflare
    if (empty($this->messageBuffer)) {
        $this->messageBuffer = $this->client->pull(
            $this->batchSize(),          // how many to pull
            $this->visibilityTimeoutMs() // how long to hide them
        );
    }

    // Step 2: If still empty (queue has no messages), return null
    if (empty($this->messageBuffer)) {
        return null;
    }

    // Step 3: Take one message from the front of the buffer
    $message = array_shift($this->messageBuffer);

    // Step 4: Wrap it in a CloudflareJob and return it
    return new CloudflareJob(
        $this->container,
        $this->client,
        $message,
        ['raw_handler' => $this->config['raw_handler'] ?? null],
        $this->connectionName,
        $this->getQueue($queue),
    );
}
```

The buffer is a simple PHP array. `array_shift()` removes and returns the first element. The next `pop()` call gets the second element without touching Cloudflare.

### The `dispatchAfterCommit` Property

```php
public function __construct(
    private readonly CloudflareClient $client,
    private readonly array $config,
) {
    $this->dispatchAfterCommit = $this->config['after_commit'] ?? false;
}
```

`dispatchAfterCommit` is a property on the base `Queue` class. When `true`, Laravel's `enqueueUsing()` method (which `push()` calls internally) holds the job in memory until the current database transaction commits, then dispatches it.

**Why this matters:**
```php
DB::transaction(function () use ($order) {
    $order->save();
    SendConfirmationEmail::dispatch($order);
    // With after_commit=true: email job not queued yet
    // ...order save is still pending in transaction
});
// Transaction commits HERE → now the job goes to Cloudflare
// Worker can't pick it up before the order is in the DB
```

Without this, the worker might pick up `SendConfirmationEmail` before `$order->save()` commits, causing "Order not found" errors.

### Queue Inspection Methods

```php
public function pendingSize($queue = null): int { return $this->size($queue); }
public function delayedSize($queue = null): int { return 0; }
public function reservedSize($queue = null): int { return 0; }

public function pendingJobs($queue = null): Collection { return new Collection(); }
public function delayedJobs($queue = null): Collection { return new Collection(); }
// ... etc
```

Cloudflare's REST API only exposes `message_backlog_count` — a single total count. It doesn't break down pending vs delayed vs in-flight (reserved) messages. So:
- `pendingSize()` returns the total backlog (best approximation)
- `delayedSize()` and `reservedSize()` return 0 (not available)
- All `*Jobs()` methods return empty collections (same as SQS and Beanstalkd)

This is an honest representation of what the API supports. Not implementing these methods at all would crash Laravel Horizon or any code that calls them.

### `deleteReserved` and `deleteAndRelease`

```php
public function deleteReserved(string $queue, string $leaseId): void
{
    $this->client->ack($leaseId);
}

public function deleteAndRelease(string $queue, CloudflareJob $job, int $delay): void
{
    $this->client->retry($job->getLeaseId(), max(0, $delay));
}
```

These methods are called by Laravel's Worker in specific retry/failure scenarios. Without them, certain edge cases in job processing (like jobs that time out or get manually released) would not properly clean up the message in Cloudflare, causing duplicates.

`deleteReserved` — ACKs the message (deletes it from Cloudflare).
`deleteAndRelease` — Sends the message back to the queue with a delay (for retry).

### Why `clear()` Throws

```php
public function clear($queue = null): int
{
    throw new \RuntimeException(
        'Cloudflare Queues does not support clearing the queue via the REST API. '
        . 'Use the Cloudflare dashboard or `wrangler queues purge <queue-name>` instead.'
    );
}
```

Cloudflare's consumer REST API has no purge endpoint. Rather than silently doing nothing (which would make `queue:clear` appear successful but do nothing), we throw a descriptive exception that tells the developer exactly what to do instead.

---

## 5. `CloudflareJob.php`

### What it is

A Job object returned by `CloudflareQueue::pop()`. The Worker calls methods on this object to process, acknowledge, or release the message.

### Constructor

```php
public function __construct(
    Container $container,           // Laravel's IoC container (for resolving dependencies)
    protected readonly CloudflareClient $client,  // to ack/retry this specific message
    protected readonly array $message,            // the raw CF message array
    protected readonly array $config,             // includes raw_handler setting
    string $connectionName,
    protected readonly string $queueName,
)
```

The job holds a reference to the `CloudflareClient` so it can make API calls when `delete()` or `release()` is called.

### `delete()` — Called on Success

```php
public function delete(): void
{
    parent::delete();                           // marks job as deleted in memory
    $this->client->ack($this->message['lease_id']); // tells Cloudflare to delete it
}
```

Called by Laravel's Worker after `handle()` completes successfully. The `lease_id` is the receipt token from when the message was pulled. Using it in the ACK call permanently removes the message from Cloudflare.

### `release()` — Called on Retry

```php
public function release($delay = 0): void
{
    parent::release($delay);                    // marks job as released in memory
    $this->client->retry(
        $this->message['lease_id'],
        max(0, (int) $delay)                   // delay in seconds before retrying
    );
}
```

Called when a job fails but should be retried. The `retry` call tells Cloudflare: make this message visible again after `$delay` seconds. The `max(0, ...)` prevents negative delays.

### `getRawBody()` — Two Modes

```php
public function getRawBody(): string
{
    // Mode 1: Standard Laravel job — return body as-is
    if (! $this->config['raw_handler']) {
        return $this->message['body'];
    }

    // Mode 2: Raw CF Worker message — wrap it
    $data = json_decode($this->message['body'], associative: true) ?? [];
    $data['uuid'] = (string) Str::uuid();
    return json_encode($data);
}
```

**Mode 1 — Standard Laravel job:**
The body is already in Laravel's serialized format. Return it as-is. Laravel's `CallQueuedHandler` will deserialize it and call `handle()`.

**Mode 2 — Raw CF Worker message:**
The body is plain JSON from a Worker. We decode it into a PHP array, add a `uuid` (required by Laravel's job system for tracking), and re-encode it. The `?? []` handles the case where the body is not valid JSON — rather than crashing, we treat it as an empty array.

**Fixed bug from the community package:** The original package used `Ramsey\Uuid\Uuid` which wasn't in `composer.json` (would crash). We use `Str::uuid()` which is built into Laravel.

### `payload()` — Building the Laravel Job Payload

```php
public function payload(): array
{
    // Mode 1: Standard Laravel job
    if (! $this->config['raw_handler']) {
        return parent::payload(); // deserializes the body JSON normally
    }

    // Mode 2: Build a synthetic payload for the raw_handler
    $handler = $this->config['raw_handler'];
    $data    = json_decode($this->message['body'], associative: true) ?? [];

    return [
        'id'          => $this->message['id'],
        'uuid'        => (string) Str::uuid(),
        'displayName' => $handler,
        'job'         => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data'        => [
            'commandName' => $handler,
            'command'     => serialize(new $handler($data)), // instantiate handler with data
        ],
    ];
}
```

For raw CF Worker messages, we construct the exact payload structure Laravel's `CallQueuedHandler` expects:
- `displayName` — which class to display in logs/Horizon
- `job` — tells Laravel to use `CallQueuedHandler@call` to invoke the job
- `data.commandName` — the class name
- `data.command` — a serialized instance of the handler class, initialized with the raw data

When Laravel processes this, it unserializes `data.command` into a `$handler` instance and calls `$handler->handle()` — exactly like a normal queued job.

---

## How the Five Files Work Together — Full Flow

### Dispatching a Job

```
1. App code: SendEmailJob::dispatch($user)
   
2. Laravel serializes $user into JSON payload
   
3. Laravel calls: CloudflareQueue::push(SendEmailJob, ...)
   
4. push() calls: pushRaw($payload, $queue)
   
5. pushRaw() calls: CloudflareClient::send($payload, $delay=0)
   
6. CloudflareClient makes HTTP request:
   POST https://api.cloudflare.com/.../messages
   Body: { "body": "{...json payload...}", "content_type": "text" }
   
7. Cloudflare stores the message
   → Done. Dispatch returns immediately.
```

### Processing a Job

```
1. Worker loop: $job = CloudflareQueue::pop()
   
2. pop() checks: is messageBuffer empty?
   → Yes: CloudflareClient::pull(batchSize=10, visibilityTimeoutMs=30000)
          → POST .../messages/pull
          → Cloudflare returns 10 messages
          → Store 9 in messageBuffer, return first as $message
   
3. pop() creates: new CloudflareJob(container, client, $message, config, ...)
   
4. Worker calls: $job->fire()
   → $job->getRawBody() → returns the JSON payload
   → Laravel's CallQueuedHandler deserializes it
   → SendEmailJob::handle() is called
   
5a. Success path:
   Worker calls: $job->delete()
   → CloudflareClient::ack($message['lease_id'])
   → POST .../messages/ack  { acks: [{ lease_id: "..." }] }
   → Cloudflare deletes the message permanently ✅

5b. Failure path (retryable):
   Worker calls: $job->release($delay)
   → CloudflareClient::retry($message['lease_id'], $delay)
   → POST .../messages/ack  { retries: [{ lease_id: "...", delay_seconds: 30 }] }
   → Cloudflare makes message visible again after 30s
   
5c. Failure path (max retries exceeded):
   Worker calls: $job->fail($exception)
   → Job is moved to failed_jobs DB table
   → $job->delete() is called internally (removes from CF)
```
