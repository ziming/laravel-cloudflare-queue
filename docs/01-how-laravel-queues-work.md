# How Laravel Queues Work (From Scratch)

Before understanding this package, you need to understand how Laravel's queue system works internally. This document explains the full picture — from the moment you dispatch a job to the moment it runs.

---

## The Problem Queues Solve

Imagine a user places an order. Your controller needs to:

1. Save the order to the database
2. Charge the credit card
3. Send a confirmation email
4. Send an SMS
5. Update inventory
6. Notify the warehouse

If you do all of this synchronously inside the request, the user waits for all 6 steps before seeing a response. That's slow and fragile — if the email server is temporarily down, the entire order fails.

**Queues solve this by splitting work into two phases:**

```
Phase 1 — During the HTTP request (fast):
  User clicks "Place Order"
       ↓
  Controller saves order + adds jobs to queue
       ↓
  Returns "Order Confirmed!" to user immediately
  Total time: ~50ms

Phase 2 — In the background (async, doesn't affect user):
  Queue worker picks up jobs one by one
  Sends email, sends SMS, updates inventory...
  If one fails, it retries automatically
```

The user experience is instant. The heavy work happens invisibly in the background.

---

## The Three Actors in Laravel's Queue System

There are three separate things that work together:

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. YOUR APPLICATION (Producer)                                  │
│                                                                 │
│    SomeController.php:                                          │
│      SendEmailJob::dispatch($user, $order);                     │
│    → Serializes the job and puts it in the queue storage        │
└───────────────────────────┬─────────────────────────────────────┘
                            │ writes to
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. THE QUEUE STORAGE (The queue itself)                         │
│                                                                 │
│    Could be: Redis, MySQL database, SQS, Cloudflare Queue...   │
│    Stores serialized job payloads and waits for a worker        │
└───────────────────────────┬─────────────────────────────────────┘
                            │ reads from
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. THE QUEUE WORKER (Consumer)                                  │
│                                                                 │
│    php artisan queue:work                                       │
│    → Runs as a long-lived process on your server                │
│    → Continuously polls the queue for new jobs                  │
│    → Deserializes and executes each job                         │
└─────────────────────────────────────────────────────────────────┘
```

Your application and the worker are **completely separate processes**. The application writes, the worker reads. They only communicate through the queue storage.

---

## What Is a "Job"?

A Job is a PHP class that represents a piece of work to be done. You create one with:

```bash
php artisan make:job SendConfirmationEmail
```

This creates a file like:

```php
// app/Jobs/SendConfirmationEmail.php

class SendConfirmationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Order $order
    ) {}

    public function handle(): void
    {
        // This runs in the background worker
        Mail::to($this->user->email)->send(new OrderConfirmation($this->order));
    }
}
```

The `ShouldQueue` interface is what marks this class as "this should be run via the queue, not immediately."

The traits add helper methods:
- `Dispatchable` — adds the `::dispatch()` static method
- `InteractsWithQueue` — adds `$this->release()`, `$this->delete()`, `$this->fail()` inside the job
- `Queueable` — adds `->onQueue()`, `->delay()`, `->onConnection()` on the dispatch call
- `SerializesModels` — automatically serializes/deserializes Eloquent models

---

## What Happens When You Dispatch a Job

```php
SendConfirmationEmail::dispatch($user, $order);
```

**Step 1 — Serialization**

Laravel takes the job object and serializes it into a JSON string called the **payload**:

```json
{
    "uuid": "a-unique-id",
    "displayName": "App\\Jobs\\SendConfirmationEmail",
    "job": "Illuminate\\Queue\\CallQueuedHandler@call",
    "data": {
        "commandName": "App\\Jobs\\SendConfirmationEmail",
        "command": "O:35:\"App\\Jobs\\SendConfirmationEmail\":2:{...serialized PHP object...}"
    },
    "attempts": 0,
    "id": "another-id"
}
```

This JSON string is what gets stored in the queue. Notice:
- `displayName` — which class to use
- `command` — the serialized PHP object (with all its constructor arguments)
- `attempts` — how many times it has been tried

**Step 2 — Pushed to Queue Storage**

The JSON payload is sent to wherever your queue driver stores it:
- `database` driver → inserted as a row in the `jobs` table in MySQL
- `redis` driver → pushed onto a Redis list
- `sqs` driver → sent to Amazon SQS via the AWS SDK
- `cloudflare` driver (this package) → sent to Cloudflare Queue via HTTP API

**Step 3 — Done**

The dispatch call returns. Your controller responds to the user. The job sits in the queue storage waiting for a worker.

---

## What the Worker Does (`php artisan queue:work`)

The worker is a long-running PHP process. Here's its inner loop (simplified):

```php
while (true) {
    $job = $queue->pop();         // ask the queue driver for the next job

    if ($job === null) {
        sleep($sleepSeconds);     // nothing available, wait and try again
        continue;
    }

    try {
        $job->fire();             // deserialize and call handle()
        $job->delete();           // ACK — remove from queue
    } catch (Exception $e) {
        if ($job->attempts() >= $job->maxTries()) {
            $job->fail($e);       // move to failed_jobs table
        } else {
            $job->release($delay); // put back on queue for retry
        }
    }
}
```

The key method is `$queue->pop()`. That's the queue driver's responsibility — it knows how to fetch the next available job from the specific backend (Redis, MySQL, SQS, Cloudflare, etc.).

---

## The Queue Driver System

Laravel abstracts the queue storage behind a **driver interface**. Every driver must implement the same contract:

```php
interface Queue {
    public function size($queue = null): int;
    public function push($job, $data = '', $queue = null): mixed;
    public function pushRaw($payload, $queue = null, array $options = []): mixed;
    public function later($delay, $job, $data = '', $queue = null): mixed;
    public function bulk($jobs, $data = '', $queue = null): void;
    public function pop($queue = null): ?Job;
}
```

Each driver (`DatabaseQueue`, `RedisQueue`, `SqsQueue`, etc.) implements these methods using its own specific backend. Your application code never calls these methods directly — Laravel's queue manager handles that. You just call `::dispatch()` and configure which driver to use.

This is **dependency inversion** — your job code doesn't care whether it's stored in Redis or MySQL or Cloudflare. It just says "queue this" and the driver handles the details.

---

## How Drivers Are Registered

Every queue driver needs two classes:

**1. A Connector** — creates the queue instance from config:
```php
class CloudflareConnector implements ConnectorInterface {
    public function connect(array $config): CloudflareQueue {
        return new CloudflareQueue(new CloudflareClient($config), $config);
    }
}
```

**2. A Queue class** — does the actual work (push, pop, ack, etc.):
```php
class CloudflareQueue extends Queue implements QueueContract {
    public function push($job, ...) { ... }
    public function pop($queue = null): ?CloudflareJob { ... }
    // etc.
}
```

The connector is registered in a Service Provider:
```php
class CloudflareQueueServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->afterResolving('queue', function ($manager) {
            $manager->addConnector('cloudflare', fn() => new CloudflareConnector());
        });
    }
}
```

When you set `QUEUE_CONNECTION=cloudflare`, Laravel calls:
```
QueueManager::connection('cloudflare')
  → looks up connector for 'cloudflare' driver
  → calls CloudflareConnector::connect($config)
  → returns CloudflareQueue instance
  → Worker uses CloudflareQueue::pop() in its loop
```

---

## The Job Contract (What a "Job" Looks Like to the Worker)

When `pop()` returns a job, the worker receives an object implementing:

```php
interface Job {
    public function fire(): void;      // deserialize and run handle()
    public function delete(): void;    // remove from queue (ACK)
    public function release($delay);   // put back on queue for retry
    public function attempts(): int;   // how many times tried
    public function getRawBody(): string; // the raw JSON payload
    public function getJobId(): string;
}
```

Each driver has its own Job implementation that knows how to `delete()` and `release()` for that specific backend:
- `DatabaseJob::delete()` → `DELETE FROM jobs WHERE id = ?`
- `RedisJob::delete()` → `ZREM queue:reserved $payload`
- `SqsJob::delete()` → `$sqs->deleteMessage([...])`
- `CloudflareJob::delete()` → `POST /messages/ack` with `{ acks: [{ lease_id }] }`

---

## What Happens When a Job Fails

If `handle()` throws an exception:

1. Worker catches it
2. If `attempts < maxTries` → calls `job->release($delay)` (puts it back with a delay)
3. If `attempts >= maxTries` → calls `job->fail($e)`:
   - Moves the job to the `failed_jobs` database table
   - Fires a `JobFailed` event
   - Calls `$job->failed($e)` on the job class if the method exists

You can then view failed jobs with:
```bash
php artisan queue:failed
php artisan queue:retry <id>   # retry a specific failed job
php artisan queue:flush        # delete all failed jobs
```

---

## The Complete Picture

```
Your Code                    Queue Storage              Worker Process
────────────────             ─────────────              ──────────────
SendEmail::dispatch()
  → serialize job
  → call pushRaw()    ──────▶  stores payload   
  → return            

                                                  loop:
                                                    job = pop()
                                                       ← fetches payload
                                                    deserialize job
                                                    call handle()
                                                    if success:
                                                      delete() → ACK
                                                    if fail:
                                                      release() → retry
                                                      or fail() → dead letter
```

Every queue driver plugs into this system at the `pop()`, `pushRaw()`, `delete()`, and `release()` points. Everything else (serialization, retries, failed jobs, delays) is handled by Laravel itself — the driver just needs to store and retrieve raw payloads reliably.
