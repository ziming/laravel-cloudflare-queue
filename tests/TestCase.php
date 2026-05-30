<?php

namespace OssyCodes\LaravelCloudflareQueue\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use OssyCodes\LaravelCloudflareQueue\CloudflareQueueServiceProvider;

class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CloudflareQueueServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.connections.cloudflare', [
            'driver'               => 'cloudflare',
            'account_id'           => 'test-account-id',
            'queue_id'             => 'test-queue-id',
            'api_token'            => 'test-api-token',
            'batch_size'           => 10,
            'visibility_timeout_ms' => 30_000,
            'after_commit'         => false,
        ]);
    }

    protected function makeClient(array $overrides = []): \OssyCodes\LaravelCloudflareQueue\CloudflareClient
    {
        return new \OssyCodes\LaravelCloudflareQueue\CloudflareClient(array_merge([
            'account_id' => 'test-account-id',
            'queue_id'   => 'test-queue-id',
            'api_token'  => 'test-api-token',
        ], $overrides));
    }

    protected function makeQueue(
        \OssyCodes\LaravelCloudflareQueue\CloudflareClient $client,
        array $config = []
    ): \OssyCodes\LaravelCloudflareQueue\CloudflareQueue {
        $queue = new \OssyCodes\LaravelCloudflareQueue\CloudflareQueue(
            $client,
            array_merge([
                'batch_size'            => 10,
                'visibility_timeout_ms' => 30_000,
                'after_commit'          => false,
            ], $config)
        );

        $queue->setContainer($this->app);
        $queue->setConnectionName('cloudflare');

        return $queue;
    }

    protected function makeMessage(array $overrides = []): array
    {
        return array_merge([
            'id'       => 'msg-'.uniqid(),
            'body'     => json_encode(['uuid' => 'test-uuid', 'job' => 'SomeJob']),
            'lease_id' => 'lease-'.uniqid(),
            'attempts' => 1,
        ], $overrides);
    }
}
