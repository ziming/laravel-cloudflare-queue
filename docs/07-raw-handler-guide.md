# Raw Handler Guide — Processing Cloudflare Worker Messages in Laravel

This guide explains the `raw_handler` feature end-to-end: what it is, why it exists, exactly how it works in the code, and a complete real-world example.

---

## Do You Even Need This?

**Most Laravel applications don't need `raw_handler` at all.** If Laravel is both the producer and the consumer — meaning you dispatch jobs with `::dispatch()` and process them with `queue:work` — leave `raw_handler = null` and skip this guide entirely.

```php
// This is all you need for standard Laravel usage
SendWelcomeEmail::dispatch($user);   // Laravel serializes it
// queue:work picks it up, deserializes it, calls handle()
// raw_handler plays no part whatsoever
```

`raw_handler` is only needed in one specific scenario: a **Cloudflare Worker** (not Laravel) is pushing messages to the queue.

```
Who is pushing messages to the queue?
        │
        ├── Laravel only  (::dispatch())
        │       → raw_handler = null        ← don't need it, skip this guide
        │
        ├── Cloudflare Worker only
        │       → raw_handler = YourHandler::class   ← you need this guide
        │
        └── Both Laravel and a Cloudflare Worker
                → two separate connections, two separate Cloudflare Queues
                  one with raw_handler, one without  ← see the end of this guide
```

---

## The Problem It Solves

When **Laravel** dispatches a job, it wraps it in a specific serialized format:

```json
{
    "uuid": "abc-123",
    "displayName": "App\\Jobs\\SendEmail",
    "job": "Illuminate\\Queue\\CallQueuedHandler@call",
    "data": {
        "commandName": "App\\Jobs\\SendEmail",
        "command": "O:22:\"App\\Jobs\\SendEmail\":1:{...serialized PHP object...}"
    }
}
```

Laravel's worker knows exactly how to read this format: it finds `data.command`, unserializes the PHP object, and calls `handle()`.

But when a **Cloudflare Worker** pushes a message, it just sends plain JSON:

```javascript
await env.EMAIL_JOB_QUEUE.send({
    email_to:   "user@example.com",
    subject:    "Order confirmed",
    body_html:  "<h1>Thank you!</h1>"
});
```

This arrives in the queue as:
```json
{
    "email_to": "user@example.com",
    "subject": "Order confirmed",
    "body_html": "<h1>Thank you!</h1>"
}
```

No `uuid`, no `displayName`, no serialized PHP object. If you try to process this with Laravel's worker as-is, it will crash — it doesn't know what class to run.

**`raw_handler` is the bridge** — it tells the package: "when you see a plain JSON message, decode it and route it to this PHP class."

---

## How It Works Inside the Package

When the worker calls `pop()` and the queue has a message, the package creates a `CloudflareJob`. The `config['raw_handler']` is passed into it:

```php
// CloudflareQueue.php — pop()
return new CloudflareJob(
    $this->container,
    $this->client,
    $message,                                              // raw CF message
    ['raw_handler' => $this->config['raw_handler'] ?? null], // ← your handler class
    $this->connectionName,
    $this->getQueue($queue),
);
```

Then when Laravel's worker asks for the job payload, `CloudflareJob::payload()` kicks in:

```php
// CloudflareJob.php — payload()
public function payload(): array
{
    // No raw_handler? Parse the body as a normal Laravel job payload.
    if (! $this->config['raw_handler']) {
        return parent::payload();
    }

    $handler = $this->config['raw_handler'];
    // e.g. "App\Jobs\HandleIncomingEmailJob"

    // Decode the plain JSON from the CF Worker
    $data = json_decode($this->message['body'], associative: true) ?? [];
    // $data = ['email_to' => 'user@example.com', 'subject' => 'Order confirmed', ...]

    // Build the exact structure Laravel's CallQueuedHandler expects
    return [
        'uuid'        => (string) Str::uuid(),
        'displayName' => $handler,
        'job'         => 'Illuminate\Queue\CallQueuedHandler@call',
        'data'        => [
            'commandName' => $handler,
            'command'     => serialize(new $handler($data)),
            //                         ↑
            //               instantiates your handler class
            //               passing $data as the constructor argument
            //               then PHP-serializes the object
        ],
    ];
}
```

Laravel's `CallQueuedHandler` then:
1. Reads `data.command`
2. PHP-unserializes it back into your `HandleIncomingEmailJob` object
3. Resolves dependencies from the container
4. Calls `->handle()`

From Laravel's worker perspective, processing a raw CF Worker message is **identical** to processing a normal dispatched job.

---

## Complete Flow — From CF Worker to Laravel handler

```
Cloudflare Worker (producer)
  │
  │  await env.EMAIL_JOB_QUEUE.send({
  │      email_to: "user@example.com",
  │      subject:  "Order confirmed",
  │      body_html: "<h1>Thank you!</h1>"
  │  });
  │
  ▼
Cloudflare Queue (storage)
  │  stores: {"email_to":"user@example.com","subject":"Order confirmed","body_html":"..."}
  │
  ▼
CloudflareQueue::pop()
  │  pulls message via POST /messages/pull
  │  creates CloudflareJob(message, config['raw_handler'] = HandleIncomingEmailJob::class)
  │
  ▼
Laravel Worker calls CloudflareJob::payload()
  │  detects raw_handler is set
  │  decodes JSON → $data = ['email_to' => ..., 'subject' => ..., 'body_html' => ...]
  │  instantiates: new HandleIncomingEmailJob($data)
  │  serializes the object
  │  returns synthetic Laravel payload
  │
  ▼
Laravel's CallQueuedHandler
  │  unserializes HandleIncomingEmailJob object
  │  calls HandleIncomingEmailJob::handle()
  │
  ▼
HandleIncomingEmailJob::handle()
  │  validates $this->data
  │  sends email via Mail::send()
  │
  ▼
CloudflareJob::delete()   ← called by worker on success
  │  POST /messages/ack
  │  message permanently deleted from Cloudflare Queue ✅
```

---

## Complete Example

### 1. The Cloudflare Worker (producer)

```javascript
// wrangler.toml must declare the queue binding:
// [[queues.producers]]
// queue = "my-email-queue"
// binding = "EMAIL_JOB_QUEUE"

export default {
    async fetch(request, env) {
        await env.EMAIL_JOB_QUEUE.send({
            email_to:   "user@example.com",
            email_from: "noreply@yourdomain.com",  // optional
            subject:    "Your order has been confirmed",
            body_html:  "<h1>Thank you for your order!</h1><p>Order #12345 is confirmed.</p>",
            body_text:  "Thank you for your order! Order #12345 is confirmed."
        });

        return new Response("Email queued!");
    }
};
```

---

### 2. Config — `config/queue.php`

```php
'cloudflare' => [
    'driver'                => 'cloudflare',
    'account_id'            => env('CLOUDFLARE_ACCOUNT_ID'),
    'queue_id'              => env('CLOUDFLARE_QUEUE_ID'),
    'api_token'             => env('CLOUDFLARE_API_TOKEN'),
    'batch_size'            => 10,
    'visibility_timeout_ms' => 60_000,

    // This is the key line — tells the package which class handles raw messages
    'raw_handler'           => \App\Jobs\HandleIncomingEmailJob::class,
],
```

---

### 3. The Handler Class — `app/Jobs/HandleIncomingEmailJob.php`

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class HandleIncomingEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Retry up to 3 times before giving up
    public int $tries = 3;

    // Wait 60s, then 120s, then 240s between retries
    public array $backoff = [60, 120, 240];

    // Kill the job if it runs longer than 30 seconds
    public int $timeout = 30;

    /**
     * $data is the decoded JSON body from the Cloudflare Worker message.
     *
     * [
     *     'email_to'   => 'user@example.com',
     *     'email_from' => 'noreply@yourdomain.com',
     *     'subject'    => 'Your order has been confirmed',
     *     'body_html'  => '<h1>Thank you!</h1>',
     *     'body_text'  => 'Thank you!',
     * ]
     */
    public function __construct(public readonly array $data)
    {
    }

    public function handle(): void
    {
        // Always validate before doing any work.
        // Invalid messages should be discarded, not retried.
        if (empty($this->data['email_to'])) {
            Log::warning('[HandleIncomingEmailJob] Missing email_to — discarding.', $this->data);
            $this->delete(); // ACKs the message, removes it from CF Queue without retrying
            return;
        }

        if (empty($this->data['subject'])) {
            Log::warning('[HandleIncomingEmailJob] Missing subject — discarding.', $this->data);
            $this->delete();
            return;
        }

        if (empty($this->data['body_html']) && empty($this->data['body_text'])) {
            Log::warning('[HandleIncomingEmailJob] Missing email body — discarding.', $this->data);
            $this->delete();
            return;
        }

        $from = $this->data['email_from'] ?? config('mail.from.address');

        Mail::send([], [], function ($message) use ($from) {
            $message
                ->to($this->data['email_to'])
                ->from($from)
                ->subject($this->data['subject']);

            if (! empty($this->data['body_html'])) {
                $message->html($this->data['body_html']);
            }

            if (! empty($this->data['body_text'])) {
                $message->text($this->data['body_text']);
            }
        });

        Log::info('[HandleIncomingEmailJob] Email sent.', [
            'to'      => $this->data['email_to'],
            'subject' => $this->data['subject'],
        ]);
    }

    /**
     * Called when all $tries are exhausted.
     * This is your last chance to alert before the job goes to failed_jobs.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[HandleIncomingEmailJob] Permanently failed.', [
            'email_to'  => $this->data['email_to'] ?? 'unknown',
            'subject'   => $this->data['subject'] ?? 'unknown',
            'error'     => $exception->getMessage(),
        ]);
    }
}
```

---

### 4. Run the worker

```bash
php artisan queue:work cloudflare --sleep=3 --tries=3 --timeout=30
```

---

## Key Details to Understand

### `$this->data` is the raw JSON from the Worker

The constructor receives exactly what the CF Worker sent — decoded from JSON into a PHP array. There's no extra wrapper or metadata. Access it directly:

```php
$this->data['email_to']   // "user@example.com"
$this->data['subject']    // "Your order has been confirmed"
$this->data['body_html']  // "<h1>Thank you!</h1>"
```

---

### Discard vs Retry

There are two categories of failure:

**Discard (call `$this->delete()`)** — the message is structurally invalid. No amount of retrying will fix it. Call `$this->delete()` to ACK the message and remove it permanently:

```php
if (empty($this->data['email_to'])) {
    $this->delete(); // gone forever, no retry
    return;
}
```

**Retry (throw an exception)** — a transient failure. The mail server is down, there's a network timeout, etc. Just throw (or let the exception bubble up naturally). Laravel will catch it and call `release($backoff)` automatically, which tells Cloudflare to make the message visible again after the backoff delay:

```php
public function handle(): void
{
    // Don't catch this — let it bubble up so Laravel retries it
    Mail::to($this->data['email_to'])->send(new MyMail($this->data));
}
```

---

### `$tries`, `$backoff`, `$timeout`

These are standard Laravel job properties — they work exactly the same as on any other queued job:

```php
public int $tries = 3;         // attempt at most 3 times
public array $backoff = [60, 120, 240]; // wait 60s after 1st fail, 120s after 2nd, 240s after 3rd
public int $timeout = 30;      // kill if running > 30 seconds (prevents stuck jobs)
```

---

### `raw_handler` applies to ALL messages on that connection

If `raw_handler` is set, **every** message on that connection goes through the handler — including any messages accidentally pushed by Laravel's own `::dispatch()`. 

If you have a mixed queue (some Laravel jobs, some CF Worker messages), use separate queue connections:

```php
// config/queue.php
'connections' => [

    // For Laravel-dispatched jobs
    'cloudflare' => [
        'driver'      => 'cloudflare',
        'queue_id'    => env('CLOUDFLARE_QUEUE_ID'),
        'raw_handler' => null, // standard Laravel jobs
    ],

    // For raw CF Worker messages only
    'cloudflare-worker' => [
        'driver'      => 'cloudflare',
        'queue_id'    => env('CLOUDFLARE_WORKER_QUEUE_ID'), // different queue
        'raw_handler' => \App\Jobs\HandleIncomingEmailJob::class,
    ],

],
```

Run separate workers for each:

```bash
php artisan queue:work cloudflare &
php artisan queue:work cloudflare-worker &
```
