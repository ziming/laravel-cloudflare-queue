# Step-by-Step Setup Guide

This guide walks you through setting up the package from zero to a working queue — including creating the Cloudflare Queue, configuring Laravel, creating your first job, and running the worker.

---

## Prerequisites

Before starting, you need:

- A Laravel application (version 10, 11, or 12)
- PHP 8.1 or higher
- A Cloudflare account (free tier works)
- Composer installed

---

## Step 1 — Create a Cloudflare Queue

### Option A — Via Cloudflare Dashboard

1. Log in to [dash.cloudflare.com](https://dash.cloudflare.com)
2. In the left sidebar, click **Workers & Pages**
3. Click **Queues** in the sub-menu
4. Click **Create queue**
5. Give it a name, e.g., `my-laravel-queue`
6. Click **Create queue**
7. You'll see the queue created. Note its name for the next steps.

### Option B — Via Wrangler CLI

```bash
# Install Wrangler if you don't have it
npm install -g wrangler

# Log in
wrangler login

# Create the queue
wrangler queues create my-laravel-queue
```

---

## Step 2 — Create a Cloudflare API Token

Your Laravel app needs an API token with permission to read from and write to the queue.

1. Go to [dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens)
2. Click **Create Token**
3. Click **Create Custom Token** (at the bottom)
4. Give it a name like `Laravel Queue Token`
5. Under **Permissions**, add:
   - **Account** → **Cloudflare Queues** → **Edit**
6. Under **Account Resources**, select your account
7. Click **Continue to summary** then **Create Token**
8. **Copy the token now** — you won't be able to see it again

---

## Step 3 — Find Your Account ID and Queue ID

**Account ID:**
1. Go to the Cloudflare Dashboard
2. Select any domain or go to your account home
3. In the right sidebar, under "Account ID", copy the long hex string

**Queue ID:**
1. Go to **Workers & Pages** → **Queues**
2. Click on your queue
3. The Queue ID is shown on the queue details page (a UUID like `abc123-...`)

---

## Step 4 — Install the Package

```bash
cd /path/to/your/laravel-app
composer require ossycodes/laravel-cloudflare-queue
```

The service provider is auto-discovered. No manual registration needed.

---

## Step 5 — Configure the Queue Connection

Open `config/queue.php` and add a `cloudflare` connection:

```php
'connections' => [

    // ... your existing connections (database, redis, etc.) ...

    'cloudflare' => [
        'driver'                => 'cloudflare',
        'account_id'            => env('CLOUDFLARE_ACCOUNT_ID'),
        'queue_id'              => env('CLOUDFLARE_QUEUE_ID'),
        'api_token'             => env('CLOUDFLARE_API_TOKEN'),
        'batch_size'            => env('CLOUDFLARE_QUEUE_BATCH_SIZE', 1),
        'visibility_timeout_ms' => env('CLOUDFLARE_QUEUE_VISIBILITY_TIMEOUT_MS', 30000),
        'after_commit'          => false,
        'raw_handler'           => null,
    ],

],
```

---

## Step 6 — Add Environment Variables

Open your `.env` file and add:

```env
# Which queue driver to use by default
QUEUE_CONNECTION=cloudflare

# Cloudflare credentials
CLOUDFLARE_ACCOUNT_ID=your-account-id-here
CLOUDFLARE_QUEUE_ID=your-queue-id-uuid-here
CLOUDFLARE_API_TOKEN=your-api-token-here

# Optional tuning
CLOUDFLARE_QUEUE_BATCH_SIZE=1
CLOUDFLARE_QUEUE_VISIBILITY_TIMEOUT_MS=30000
```

> **Security:** Never commit your `.api_token` to git. The `.env` file is already in `.gitignore` in Laravel projects.

---

## Step 7 — Create Your First Job

Generate a job class:

```bash
php artisan make:job SendWelcomeEmail
```

This creates `app/Jobs/SendWelcomeEmail.php`. Edit it:

```php
<?php

namespace App\Jobs;

use App\Models\User;
use App\Mail\WelcomeEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendWelcomeEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // How many times to retry if it fails (default: 0 = unlimited)
    public int $tries = 3;

    // How many seconds to wait before the first retry
    public int $backoff = 30;

    public function __construct(
        public User $user  // SerializesModels handles storing/retrieving this
    ) {}

    public function handle(): void
    {
        Mail::to($this->user->email)->send(new WelcomeEmail($this->user));
    }

    // Optional: called when all retries are exhausted
    public function failed(\Throwable $exception): void
    {
        \Log::error("SendWelcomeEmail failed for user {$this->user->id}: {$exception->getMessage()}");
    }
}
```

---

## Step 8 — Dispatch the Job

From anywhere in your application (controller, service, event listener):

```php
use App\Jobs\SendWelcomeEmail;

// Dispatch immediately (goes to queue, processes when worker picks it up)
SendWelcomeEmail::dispatch($user);

// Dispatch with a 5-minute delay
SendWelcomeEmail::dispatch($user)->delay(now()->addMinutes(5));

// Dispatch to a specific queue name (if you have multiple queues)
SendWelcomeEmail::dispatch($user)->onQueue('emails');

// Dispatch synchronously (bypasses queue, runs immediately — useful for testing)
SendWelcomeEmail::dispatchSync($user);
```

At this point the job is in Cloudflare Queue waiting. Nothing runs yet until you start the worker.

---

## Step 9 — Run the Worker

Open a new terminal window and run:

```bash
# Process jobs from the cloudflare connection indefinitely
php artisan queue:work cloudflare

# With options
php artisan queue:work cloudflare \
    --sleep=3 \       # seconds to wait when queue is empty
    --tries=3 \       # max attempts per job before marking as failed
    --timeout=60 \    # max seconds a job is allowed to run
    --queue=default   # which named queue to process
```

You'll see output like:
```
[2026-05-30 10:00:00] Processing: App\Jobs\SendWelcomeEmail
[2026-05-30 10:00:01] Processed:  App\Jobs\SendWelcomeEmail
```

The worker stays running and processes jobs as they arrive.

---

## Step 10 — Keep the Worker Running in Production

In production, you don't want to manually run `queue:work`. Use a process manager to keep it running and restart it if it crashes.

### Using Supervisor (Linux servers)

Install Supervisor:
```bash
sudo apt-get install supervisor
```

Create a config file at `/etc/supervisor/conf.d/laravel-worker.conf`:
```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/app/artisan queue:work cloudflare --sleep=3 --tries=3 --timeout=60
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2                          ; run 2 worker processes
redirect_stderr=true
stdout_logfile=/path/to/your/app/storage/logs/worker.log
stopwaitsecs=3600
```

Start Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

After deploying new code, restart workers so they pick up your changes:
```bash
sudo supervisorctl restart laravel-worker:*
# OR use artisan:
php artisan queue:restart
```

---

## Step 11 — Handle Failed Jobs (Optional but Recommended)

When a job fails after all retries, Laravel moves it to a `failed_jobs` table.

Create the table:
```bash
php artisan queue:failed-table
php artisan migrate
```

Now if a job fails permanently, you can:
```bash
# See all failed jobs
php artisan queue:failed

# Retry a specific failed job
php artisan queue:retry <id>

# Retry all failed jobs
php artisan queue:retry all

# Delete a failed job
php artisan queue:forget <id>

# Delete all failed jobs
php artisan queue:flush
```

---

## Step 12 — Verify It's Working

### Test by dispatching manually

```bash
php artisan tinker

# In tinker:
App\Jobs\SendWelcomeEmail::dispatch(App\Models\User::first());
```

### Check the queue size

```php
// In tinker or a controller
Queue::connection('cloudflare')->size();
// Returns the number of messages currently in the queue
```

### Watch the worker output

Run `queue:work` in verbose mode to see detailed output:
```bash
php artisan queue:work cloudflare -vvv
```

---

## Summary of What You've Set Up

```
Laravel App (Producer)
      │
      │  SendWelcomeEmail::dispatch($user)
      │  → serializes job as JSON
      │  → POST https://api.cloudflare.com/.../messages
      ▼
Cloudflare Queue (Storage)
      │
      │  (stores the JSON payload)
      ▼
php artisan queue:work (Consumer)
      │
      │  every few seconds: POST .../messages/pull
      │  → pulls up to batch_size messages
      │  → deserializes JSON back to SendWelcomeEmail object
      │  → calls SendWelcomeEmail::handle()
      │  → if success: POST .../messages/ack
      │  → if fail: POST .../messages/ack (retry with delay)
      ▼
Result: email sent to user
```
