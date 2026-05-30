# How Cloudflare Queue Fits Into Laravel's System

Now that you understand how Laravel's queue system works internally, this document explains where Cloudflare Queue sits in that system and why you would choose it over Redis or the database driver.

---

## Recap: The Queue Driver's Job

Laravel's Worker does this every few seconds:

```
job = queue_driver.pop()   ← ASK the driver for the next job
```

The driver's only job is to **reliably store and retrieve job payloads**. It doesn't care what's in the payload — it just holds it safely until the worker asks for it.

This package makes Laravel's `pop()` call talk to **Cloudflare Queue** instead of Redis or MySQL.

---

## The Three Queue Storage Options Compared

### Option A — Database Driver (built into Laravel)

```
Worker → SELECT + lock row FROM jobs table → process → DELETE row
```

- Stored in your own MySQL/Postgres database
- Zero extra infrastructure — you already have a database
- Slower under high load (row locking causes contention)
- Great for small-medium volume or when you can't add more services

### Option B — Redis Driver (built into Laravel)

```
Worker → BLPOP from Redis list → process → ZREM from reserved set
```

- Stored in a Redis instance
- Very fast (Redis is in-memory)
- Requires running and managing a Redis server
- Best for high throughput applications
- Powers Laravel Horizon

### Option C — Cloudflare Queue (this package)

```
Worker → POST /messages/pull to Cloudflare API → process → POST /messages/ack
```

- Stored on Cloudflare's global infrastructure
- No server to manage — Cloudflare handles storage, replication, retries
- Great if your producers are Cloudflare Workers (they can push natively, no HTTP overhead)
- Polling-based (HTTP round-trip per poll cycle)
- At-least-once delivery guaranteed by Cloudflare

---

## Why Use Cloudflare Queue Specifically?

### Scenario 1: Your Producer is a Cloudflare Worker

If another part of your system  is a Cloudflare Worker, it can push to Cloudflare Queue with a single line:

```javascript
await env.EMAIL_JOB_QUEUE.send({ email_to: "user@example.com", ... });
```

This is a native, zero-overhead call inside Cloudflare's own network. No HTTP round-trip to an external service, no credentials to manage. For the Worker, this is the simplest possible way to offload work.

Your Laravel application then consumes those messages from the same queue — bridging the two worlds.

### Scenario 2: Multi-Language / Multi-Platform System

Cloudflare Queue works across any language or platform because it's accessed via a standard HTTP REST API. A Python script, a Go service, a PHP app, and a Cloudflare Worker can all push to the same queue. Your Laravel worker consumes everything from one place.

### Scenario 3: Serverless or Edge Architecture

You're building on Cloudflare's platform (Workers, Pages, etc.) and want your background processing to stay within the same ecosystem without running a Redis server.

---

## How This Package Bridges the Two Systems

```
┌──────────────────────────────────────────────────────────────────┐
│                     PRODUCERS                                    │
│                                                                  │
│  Cloudflare Worker            Laravel Application               │
│  ─────────────────            ──────────────────               │
│  env.QUEUE.send({...})        SendEmailJob::dispatch()          │
│  (native CF binding)          → CloudflareQueue::push()         │
│                               → POST /messages (HTTP)           │
└──────────────────────────────┬───────────────────────────────────┘
                               │  both write to
                               ▼
                    ┌──────────────────────┐
                    │  Cloudflare Queue    │
                    │  (cloud storage)     │
                    │                      │
                    │  stores JSON         │
                    │  payloads            │
                    └──────────┬───────────┘
                               │  Laravel worker reads from
                               ▼
┌──────────────────────────────────────────────────────────────────┐
│                     CONSUMER                                     │
│                                                                  │
│  php artisan queue:work cloudflare                               │
│                                                                  │
│  loop:                                                           │
│    CloudflareQueue::pop()                                        │
│      → POST /messages/pull (fetch up to N messages)             │
│      → CloudflareJob::fire() (deserialize + call handle())      │
│      → CloudflareJob::delete()                                   │
│           → POST /messages/ack (tell CF: done, delete it)       │
└──────────────────────────────────────────────────────────────────┘
```

---

## The Specific API Calls This Package Makes

### 1. Push a job (when you call `::dispatch()`)

```
POST https://api.cloudflare.com/client/v4/accounts/{account_id}/queues/{queue_id}/messages

Headers:
  Authorization: Bearer {api_token}
  Content-Type: application/json

Body:
  {
    "body": "{\"uuid\":\"abc\",\"displayName\":\"App\\\\Jobs\\\\SendEmail\",\"job\":\"...\",\"data\":{...}}",
    "content_type": "text"
  }
```

The `body` is Laravel's standard serialized job payload (a JSON string). Cloudflare treats it as an opaque string — it doesn't know or care what's in it.

---

### 2. Pull jobs (every poll cycle when `queue:work` runs)

```
POST .../messages/pull

Body:
  {
    "batch_size": 10,
    "visibility_timeout_ms": 30000
  }

Response:
  {
    "result": {
      "messages": [
        {
          "id": "msg-abc",
          "body": "{\"uuid\":\"abc\",\"displayName\":\"App\\\\Jobs\\\\SendEmail\",...}",
          "lease_id": "lease-xyz",
          "attempts": 1
        }
      ]
    }
  }
```

`batch_size` — how many messages to pull at once. With the buffer, pulling 10 saves 9 round-trips.

`visibility_timeout_ms` — after pulling, how long the message is "hidden" from all other consumers. If the worker doesn't ACK within this window, the message reappears for another worker to pick up. This is how Cloudflare handles retries automatically.

`lease_id` — a temporary receipt token. You must use this to ACK or retry the message. It expires after `visibility_timeout_ms`.

---

### 3. Acknowledge a job (when `job->delete()` is called after successful processing)

```
POST .../messages/ack

Body:
  {
    "acks": [{ "lease_id": "lease-xyz" }],
    "retries": []
  }
```

This tells Cloudflare: "I successfully processed this message, you can permanently delete it." Without this ACK, the message reappears after the visibility timeout and gets retried.

---

### 4. Release a job back (when `job->release($delay)` is called on failure)

```
POST .../messages/ack

Body:
  {
    "acks": [],
    "retries": [{ "lease_id": "lease-xyz", "delay_seconds": 30 }]
  }
```

This tells Cloudflare: "Something went wrong, make this message available again in 30 seconds." Laravel calls this when a job throws an exception but hasn't hit its max retry limit yet.

---

## The Visibility Timeout — The Heart of Reliability

This is the most important concept to understand. When you pull a message, Cloudflare hides it from everyone for `visibility_timeout_ms` milliseconds.

```
Timeline for a single message:

T=0s    Worker A pulls message
        → message is now INVISIBLE to all consumers for 30 seconds

T=0s    Worker A starts processing (calls handle())

T=2s    Processing completes successfully
        Worker A calls delete() → POST /messages/ack
        → message permanently deleted ✅

────────────────────────────────────────────────────────
What if processing fails?

T=0s    Worker A pulls message → message invisible for 30s
T=0s    Worker A starts processing
T=5s    Server crashes or job throws unrecoverable exception
        Worker A never calls ack()

T=30s   Visibility timeout expires
        → message becomes VISIBLE again automatically
        
T=35s   Worker B pulls the same message
        → attempts counter is now 2
        → Worker B processes it successfully → ack()
        → message permanently deleted ✅
```

This is **at-least-once delivery** — a message will be delivered at least once (possibly more times if things fail). Your job's `handle()` method should ideally be idempotent (safe to run multiple times with the same result) to handle this.

---

## The `batch_size` and the In-Memory Buffer

### The Problem Without a Buffer

Laravel's Worker calls `pop()` once per job:

```
Worker loop:
  job1 = queue.pop()   → HTTP request to Cloudflare → returns 1 message
  process job1
  job2 = queue.pop()   → HTTP request to Cloudflare → returns 1 message
  process job2
  job3 = queue.pop()   → HTTP request to Cloudflare → returns 1 message
  ...
```

If you have 100 jobs waiting, that's 100 separate HTTP round-trips to Cloudflare. At ~50ms per request, that's 5 seconds just in network overhead.

### The Solution — In-Memory Buffer

This package buffers messages locally:

```
Worker loop:
  job1 = queue.pop()
    → buffer is empty, pull from Cloudflare with batch_size=10
    → Cloudflare returns 10 messages in ONE request
    → store 9 in local buffer, return first one
    → process job1

  job2 = queue.pop()
    → buffer has 9 messages
    → take from buffer — NO HTTP request
    → process job2

  job3 = queue.pop()   → from buffer (no HTTP)
  job4 = queue.pop()   → from buffer (no HTTP)
  ...
  job10 = queue.pop()  → from buffer (no HTTP, buffer drained)

  job11 = queue.pop()
    → buffer is empty, pull from Cloudflare again
    → ONE more HTTP request for the next batch
```

With `batch_size=10`, you make 1 HTTP request per 10 jobs instead of 10 requests per 10 jobs.

### The `visibility_timeout_ms` Must Be Long Enough

When messages sit in the buffer (not yet processed), they are still "invisible" in Cloudflare. If they sit in the buffer longer than `visibility_timeout_ms`, Cloudflare makes them visible again — and another worker will pull the same messages, causing duplicates.

**Rule:** `visibility_timeout_ms` must be greater than `batch_size × average_job_processing_time`.

Example:
- `batch_size = 10`
- Average job takes 2 seconds
- Worst case: jobs 2-10 wait in buffer while job 1 processes (9 × 2s = 18s)
- Set `visibility_timeout_ms` to at least `30_000` (30 seconds) as a safe margin

---

## Standard Laravel Jobs vs Raw Cloudflare Worker Messages

This package handles two different types of messages:

### Type 1 — Standard Laravel Jobs

When Laravel dispatches a job, it serializes it in a specific format with `displayName`, `uuid`, `job`, `data.commandName`, `data.command` (serialized PHP object). The worker knows how to deserialize this and call `handle()`.

This is the default mode. No special configuration needed.

### Type 2 — Raw Messages from a Cloudflare Worker

A Cloudflare Worker might push a message that doesn't follow Laravel's format:

```javascript
// Cloudflare Worker (not Laravel)
await env.MY_QUEUE.send({
    email_to: "user@example.com",
    subject: "Hello!"
});
```

This arrives as `{"email_to":"user@example.com","subject":"Hello!"}` — plain JSON, no Laravel serialization.

The `raw_handler` config tells this package: "when you get a message that came from a Worker, wrap it and route it to this class."

```php
// config/queue.php
'cloudflare' => [
    'raw_handler' => App\Jobs\HandleWorkerMessage::class,
],
```

The package then:
1. Decodes the raw JSON into a PHP array
2. Instantiates `HandleWorkerMessage($data)`
3. Wraps it in Laravel's job format so `queue:work` can process it normally

This is what makes the bridge between Cloudflare Workers and Laravel seamless.

For a complete walkthrough — including the full handler class, validation, retry vs discard logic, and how to handle mixed queues — see **[07 — Raw Handler Guide](./07-raw-handler-guide.md)**.
