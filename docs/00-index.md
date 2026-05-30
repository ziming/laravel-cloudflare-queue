# Documentation Index

Welcome to the complete documentation for `ossycodes/laravel-cloudflare-queue`.

If you're new to Laravel queues or Cloudflare, start from the top. If you already know Laravel queues, jump to doc 02.

---

## Documents

### [01 — How Laravel Queues Work (From Scratch)](./01-how-laravel-queues-work.md)
Start here if you're new to queues. Explains:
- What problem queues solve
- The three actors: your app, the queue storage, and the worker
- What a Job is and what happens when you dispatch one
- How the Worker loop works (`queue:work`)
- How queue drivers plug into Laravel
- The Job contract (delete, release, attempts)

---

### [02 — How Cloudflare Queue Fits In](./02-how-cloudflare-queue-fits-in.md)
Explains the specific role Cloudflare Queue plays:
- How this driver compares to Database, Redis, and SQS drivers
- Why you would choose Cloudflare Queue
- The exact API calls this package makes (pull, ack, retry)
- How the visibility timeout creates automatic retries
- The in-memory batch buffer and why it matters
- Standard Laravel jobs vs raw Cloudflare Worker messages

---

### [03 — Package Internals](./03-package-internals.md)
Deep dive into every file in `src/`:
- `CloudflareQueueServiceProvider` — how the driver registers itself
- `CloudflareConnector` — how config becomes a live queue object
- `CloudflareClient` — every HTTP call explained
- `CloudflareQueue` — the buffer, inspection methods, `dispatchAfterCommit`
- `CloudflareJob` — `delete()`, `release()`, `getRawBody()`, `payload()`
- The complete dispatch and processing flow end-to-end

---

### [04 — Step-by-Step Setup Guide](./04-step-by-step-setup.md)
Practical walkthrough from zero to working:
1. Create a Cloudflare Queue (dashboard or CLI)
2. Create an API token
3. Find Account ID and Queue ID
4. Install the package
5. Configure `config/queue.php`
6. Add `.env` variables
7. Create your first job
8. Dispatch the job
9. Run the worker
10. Keep the worker running in production (Supervisor)
11. Handle failed jobs
12. Verify everything works

---

### [05 — Configuration Reference](./05-configuration-reference.md)
Every config option documented:
- `account_id`, `queue_id`, `api_token` — required credentials
- `batch_size` — how many messages per pull (and how to size it)
- `visibility_timeout_ms` — the retry window (and how to size it correctly)
- `after_commit` — dispatch only after DB transaction commits
- `raw_handler` — handle messages from Cloudflare Workers
- `queue` — default queue name
- Multiple connection setup

---

### [07 — Raw Handler Guide](./07-raw-handler-guide.md)
Complete guide to the `raw_handler` feature:
- What problem it solves (CF Worker messages vs Laravel job format)
- Exactly how the wrapping works inside `CloudflareJob::payload()`
- Full end-to-end flow diagram
- Complete real-world example (CF Worker → queue → Laravel handler → email)
- Discard vs retry — when to call `$this->delete()` vs let exceptions bubble
- How to handle mixed queues (some Laravel jobs, some Worker messages)

---

### [06 — Testing Guide](./06-testing.md)
How to test queue behavior:
- `Queue::fake()` — intercept dispatches without touching Cloudflare
- Testing job classes directly
- Running jobs synchronously in tests
- How the package's own tests work (mocked HTTP, mocked client)
- Writing integration tests against real Cloudflare API

---

## Quick Reference

### Minimum Setup

```php
// config/queue.php
'cloudflare' => [
    'driver'     => 'cloudflare',
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'queue_id'   => env('CLOUDFLARE_QUEUE_ID'),
    'api_token'  => env('CLOUDFLARE_API_TOKEN'),
],
```

```env
QUEUE_CONNECTION=cloudflare
CLOUDFLARE_ACCOUNT_ID=...
CLOUDFLARE_QUEUE_ID=...
CLOUDFLARE_API_TOKEN=...
```

### Create and Dispatch a Job

```bash
php artisan make:job MyJob
```

```php
// In a controller or service:
MyJob::dispatch($data);
MyJob::dispatch($data)->delay(now()->addMinutes(5));
MyJob::dispatch($data)->onQueue('high-priority');
```

### Run the Worker

```bash
php artisan queue:work cloudflare --sleep=3 --tries=3
```

### Batch Processing

```php
// config/queue.php
'batch_size'            => 10,
'visibility_timeout_ms' => 60_000, // must cover batch_size × avg job time
```

### Handle Raw CF Worker Messages

```php
// config/queue.php
'raw_handler' => App\Jobs\HandleWorkerMessage::class,
```

```php
class HandleWorkerMessage implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable;
    public function __construct(public readonly array $data) {}
    public function handle(): void { /* use $this->data */ }
}
```
