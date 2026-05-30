<?php

namespace OssyCodes\LaravelCloudflareQueue\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Simulates a handler for raw messages pushed by a Cloudflare Worker (not by Laravel).
 */
class RawJobHandler implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly array $data = [])
    {
    }

    public function handle(): void
    {
        // no-op for testing
    }
}
