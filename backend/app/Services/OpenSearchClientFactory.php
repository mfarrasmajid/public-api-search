<?php

namespace App\Services;

use OpenSearch\Client;
use OpenSearch\ClientBuilder;

class OpenSearchClientFactory
{
    public static function make(array $config): Client
    {
        $host = sprintf(
            '%s://%s:%d',
            $config['scheme'] ?? 'http',
            $config['host'] ?? 'opensearch',
            $config['port'] ?? 9200
        );

        $builder = ClientBuilder::create()
            ->setHosts([$host])
            ->setSSLVerification((bool) ($config['verify_ssl'] ?? false))
            ->setRetries(2);

        if (! empty($config['username'])) {
            $builder->setBasicAuthentication($config['username'], (string) ($config['password'] ?? ''));
        }

        return $builder->build();
    }
}
