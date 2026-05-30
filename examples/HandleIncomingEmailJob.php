<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Handles raw email messages pushed by a Cloudflare Worker.
 *
 * -------------------------------------------------------------------------
 * DO YOU NEED THIS?
 * -------------------------------------------------------------------------
 * Most Laravel apps don't need a raw handler at all.
 *
 * If Laravel is BOTH the producer and consumer (i.e. you dispatch jobs with
 * ::dispatch() and process them with queue:work), just leave raw_handler=null
 * in your config and use a normal job class instead. This file is irrelevant.
 *
 * You only need a raw handler when a CLOUDFLARE WORKER (not Laravel) is
 * pushing messages to the queue:
 *
 *   CF Worker only pushing  →  raw_handler = this class
 *   Laravel only pushing    →  raw_handler = null  (don't use this file)
 *   Both pushing            →  two separate connections + two separate queues,
 *                              one with raw_handler, one without
 * -------------------------------------------------------------------------
 *
 * This job is NOT dispatched by Laravel — it is invoked when the Cloudflare
 * Worker sends a message like this:
 *
 *   await env.EMAIL_JOB_QUEUE.send({
 *       email_to:   "user@example.com",
 *       email_from: "noreply@test.com",   // optional
 *       subject:    "Your order is confirmed",
 *       body_html:  "<h1>Thank you!</h1>",
 *       body_text:  "Thank you!"            // optional
 *   });
 *
 * To wire this up, set in config/queue.php:
 *
 *   'cloudflare' => [
 *       'driver'      => 'cloudflare',
 *       'raw_handler' => \App\Jobs\HandleIncomingEmailJob::class,
 *       // ...
 *   ],
 *
 * The package will:
 *   1. Pull the raw JSON from Cloudflare Queue
 *   2. Decode it into $data
 *   3. Instantiate new HandleIncomingEmailJob($data)
 *   4. Call handle() — this is where your logic runs
 */
class HandleIncomingEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * How many times to attempt the job before marking it as failed.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying after a failure.
     * Each retry doubles: 60s, 120s, 240s.
     */
    public array $backoff = [60, 120, 240];

    /**
     * Maximum seconds this job is allowed to run.
     */
    public int $timeout = 30;

    /**
     * @param array $data The decoded JSON body from the Cloudflare Worker message.
     *
     * Expected shape:
     * [
     *     'email_to'   => 'user@example.com',       // required
     *     'email_from' => 'noreply@test.com',       // optional
     *     'subject'    => 'Your order is confirmed', // required
     *     'body_html'  => '<h1>Thank you!</h1>',     // at least one body required
     *     'body_text'  => 'Thank you!',              // at least one body required
     * ]
     */
    public function __construct(public readonly array $data)
    {
    }

    public function handle(): void
    {
        // Step 1 — Validate the incoming data
        // The CF Worker might push incomplete payloads; validate before proceeding.
        if (empty($this->data['email_to'])) {
            // Log and silently discard — no point retrying a structurally invalid message
            Log::warning('[HandleIncomingEmailJob] Missing email_to, discarding message.', [
                'data' => $this->data,
            ]);
            $this->delete(); // ACKs the Cloudflare message, won't be retried
            return;
        }

        if (empty($this->data['subject'])) {
            Log::warning('[HandleIncomingEmailJob] Missing subject, discarding message.', [
                'data' => $this->data,
            ]);
            $this->delete();
            return;
        }

        if (empty($this->data['body_html']) && empty($this->data['body_text'])) {
            Log::warning('[HandleIncomingEmailJob] Missing both body_html and body_text, discarding.', [
                'data' => $this->data,
            ]);
            $this->delete();
            return;
        }

        // Step 2 — Build and send the email
        $from = $this->data['email_from'] ?? config('mail.from.address');

        Log::info('[HandleIncomingEmailJob] Sending email.', [
            'to'      => $this->data['email_to'],
            'subject' => $this->data['subject'],
        ]);

        Mail::send(
            [],                             // no blade view — using raw HTML/text directly
            [],
            function ($message) use ($from) {
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
            }
        );

        Log::info('[HandleIncomingEmailJob] Email sent successfully.', [
            'to'      => $this->data['email_to'],
            'subject' => $this->data['subject'],
        ]);
    }

    /**
     * Called automatically by Laravel when all $tries are exhausted.
     *
     * This is your last chance to log, alert, or store the failed message
     * before it moves to the failed_jobs table.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[HandleIncomingEmailJob] Job permanently failed after all retries.', [
            'email_to'  => $this->data['email_to'] ?? 'unknown',
            'subject'   => $this->data['subject'] ?? 'unknown',
            'exception' => $exception->getMessage(),
        ]);

        // Optional: notify your team via Slack, PagerDuty, etc.
        // Slack::send("#alerts", "Email job failed for: {$this->data['email_to']}");
    }
}
