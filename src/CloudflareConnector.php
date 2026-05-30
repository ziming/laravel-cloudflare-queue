<?php

namespace OssyCodes\LaravelCloudflareQueue;

use Illuminate\Queue\Connectors\ConnectorInterface;
use InvalidArgumentException;

class CloudflareConnector implements ConnectorInterface
{
    public function connect(array $config): CloudflareQueue
    {
        foreach (['account_id', 'queue_id', 'api_token'] as $required) {
            if (empty($config[$required])) {
                throw new InvalidArgumentException(
                    "Cloudflare Queue config is missing required key: [{$required}]."
                );
            }
        }

        return new CloudflareQueue(new CloudflareClient($config), $config);
    }
}
