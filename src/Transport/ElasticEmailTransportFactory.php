<?php

namespace FabioTech\LaravelElasticEmail\Transport;

use ElasticEmail\Api\EmailsApi;
use ElasticEmail\Configuration;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class ElasticEmailTransportFactory
{
    private const MAX_CONNECT_TIMEOUT = 60.0;

    private const MAX_REQUEST_TIMEOUT = 600.0;

    /**
     * @param  array<string, mixed>  $config
     */
    public function make(array $config, ?LoggerInterface $logger = null): ElasticEmailTransport
    {
        $key = trim((string) ($config['key'] ?? ''));
        $connectTimeout = $this->positiveTimeout($config, 'connect_timeout', 5.0, self::MAX_CONNECT_TIMEOUT, 'connection');
        $timeout = $this->positiveTimeout($config, 'timeout', 15.0, self::MAX_REQUEST_TIMEOUT, 'request');
        $maxBodyBytes = $this->positiveInteger($config, 'max_body_bytes', 2_000_000, ElasticEmailTransport::HARD_MAX_PROVIDER_BYTES);
        $maxAttachments = $this->positiveInteger($config, 'max_attachments', 10, ElasticEmailTransport::HARD_MAX_ATTACHMENTS);
        $maxAttachmentBytes = $this->positiveInteger($config, 'max_attachment_bytes', 10_000_000, ElasticEmailTransport::HARD_MAX_PROVIDER_BYTES);
        $maxMessageBytes = $this->positiveInteger($config, 'max_message_bytes', 15_000_000, ElasticEmailTransport::HARD_MAX_PROVIDER_BYTES);
        $maxPayloadBytes = $this->positiveInteger($config, 'max_payload_bytes', 20_000_000, ElasticEmailTransport::HARD_MAX_PROVIDER_BYTES);
        $maxRecipients = $this->positiveInteger($config, 'max_recipients', 1_000, ElasticEmailTransport::HARD_MAX_RECIPIENTS);
        $maxHeaderBytes = $this->positiveInteger($config, 'max_header_bytes', 16_384, ElasticEmailTransport::HARD_MAX_HEADER_BYTES);
        $maxMetadataBytes = $this->positiveInteger($config, 'max_metadata_bytes', 262_144, ElasticEmailTransport::HARD_MAX_METADATA_BYTES);
        $maxResponseBytes = $this->positiveInteger($config, 'max_response_bytes', 65_536, ElasticEmailTransport::HARD_MAX_RESPONSE_BYTES);

        if ($key === '') {
            throw new InvalidArgumentException('Elastic Email API key is not configured.');
        }

        if ($maxBodyBytes > $maxMessageBytes) {
            throw new InvalidArgumentException('Elastic Email body byte limit cannot exceed the message byte limit.');
        }

        if ($maxAttachmentBytes > $maxMessageBytes) {
            throw new InvalidArgumentException('Elastic Email attachment byte limit cannot exceed the message byte limit.');
        }

        if ($maxHeaderBytes > $maxMetadataBytes) {
            throw new InvalidArgumentException('Elastic Email header byte limit cannot exceed the metadata byte limit.');
        }

        if ($maxMetadataBytes > $maxPayloadBytes) {
            throw new InvalidArgumentException('Elastic Email metadata byte limit cannot exceed the payload byte limit.');
        }

        $configuration = (new Configuration)->setApiKey('X-ElasticEmail-ApiKey', $key);
        $handler = HandlerStack::create();
        $handler->push(new LimitResponseBody($maxResponseBytes), 'elasticemail_response_limit');
        $client = new Client([
            'allow_redirects' => false,
            'connect_timeout' => $connectTimeout,
            'decode_content' => true,
            'handler' => $handler,
            'timeout' => $timeout,
            'verify' => true,
        ]);

        return new ElasticEmailTransport(
            key: $key,
            emailsApi: new EmailsApi($client, $configuration),
            logger: $logger,
            maxBodyBytes: $maxBodyBytes,
            maxAttachments: $maxAttachments,
            maxAttachmentBytes: $maxAttachmentBytes,
            maxMessageBytes: $maxMessageBytes,
            maxPayloadBytes: $maxPayloadBytes,
            maxRecipients: $maxRecipients,
            maxHeaderBytes: $maxHeaderBytes,
            maxMetadataBytes: $maxMetadataBytes,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function positiveInteger(array $config, string $key, int $default, int $maximum): int
    {
        $value = $config[$key] ?? $default;

        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1 && strlen($value) <= 10) {
            $integer = (int) $value;
        } else {
            throw new InvalidArgumentException("Elastic Email {$key} must be a positive integer.");
        }

        if ($integer <= 0) {
            throw new InvalidArgumentException("Elastic Email {$key} must be a positive integer.");
        }

        if ($integer > $maximum) {
            throw new InvalidArgumentException("Elastic Email {$key} exceeds the supported hard limit of {$maximum}.");
        }

        return $integer;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function positiveTimeout(
        array $config,
        string $key,
        float $default,
        float $maximum,
        string $label,
    ): float {
        $value = $config[$key] ?? $default;

        if (is_int($value) || is_float($value)) {
            $timeout = (float) $value;
        } elseif (is_string($value) && preg_match('/\A(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)\z/D', $value) === 1) {
            $timeout = (float) $value;
        } else {
            throw new InvalidArgumentException("Elastic Email {$label} timeout must be a positive decimal number.");
        }

        if (! is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException("Elastic Email {$label} timeout must be greater than zero.");
        }

        if ($timeout > $maximum) {
            throw new InvalidArgumentException("Elastic Email {$label} timeout cannot exceed ".(int) $maximum.' seconds.');
        }

        return $timeout;
    }
}
