<?php

namespace OssyCodes\LaravelCloudflareQueue;

use Illuminate\Support\ServiceProvider;

class CloudflareQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving('queue', function ($manager) {
            $manager->addConnector('cloudflare', fn () => new CloudflareConnector());
        });
    }
}
