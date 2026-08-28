<?php

namespace FabioTech\LaravelElasticEmail\Transport;

use ElasticEmail\Api\EmailsApi;
use ElasticEmail\Configuration;
use GuzzleHttp\Client;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class ElasticEmailTransportFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function make(array $config, ?LoggerInterface $logger = null): ElasticEmailTransport
    {
        $key = trim((string) ($config['key'] ?? ''));
        $connectTimeout = (float) ($config['connect_timeout'] ?? 5);
        $timeout = (float) ($config['timeout'] ?? 15);

        if ($key === '') {
            throw new InvalidArgumentException('Elastic Email API key is not configured.');
        }

        if ($connectTimeout <= 0) {
            throw new InvalidArgumentException('Elastic Email connection timeout must be greater than zero.');
        }

        if ($timeout <= 0) {
            throw new InvalidArgumentException('Elastic Email request timeout must be greater than zero.');
        }

        $configuration = (new Configuration)->setApiKey('X-ElasticEmail-ApiKey', $key);
        $client = new Client([
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
        ]);

        return new ElasticEmailTransport(
            key: $key,
            emailsApi: new EmailsApi($client, $configuration),
            logger: $logger,
        );
    }
}
