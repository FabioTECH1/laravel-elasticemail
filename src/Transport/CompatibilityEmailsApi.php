<?php

namespace FabioTech\LaravelElasticEmail\Transport;

use ElasticEmail\Api\EmailsApi;
use ElasticEmail\Model\EmailTransactionalMessageData;
use ElasticEmail\ObjectSerializer;
use GuzzleHttp\Psr7\Request;

/**
 * A package-owned client that preserves the upstream SDK's behavior
 * while overriding transactional request construction to prevent
 * Guzzle 7.15+ jsonEncode deprecations.
 */
class CompatibilityEmailsApi extends EmailsApi
{
    /**
     * @param  EmailTransactionalMessageData  $email_transactional_message_data
     * @return Request
     */
    public function emailsTransactionalPostRequest($email_transactional_message_data, string $contentType = self::contentTypes['emailsTransactionalPost'][0])
    {
        $resourcePath = '/emails/transactional';
        $queryParams = [];
        $headerParams = [];
        $multipart = false;

        $headers = $this->headerSelector->selectHeaders(
            ['application/json'],
            $contentType,
            $multipart
        );

        if (stripos($headers['Content-Type'], 'application/json') !== false) {
            $httpBody = json_encode(ObjectSerializer::sanitizeForSerialization($email_transactional_message_data), JSON_THROW_ON_ERROR);
        } else {
            /** @var string $httpBody */
            $httpBody = $email_transactional_message_data;
        }

        $apiKey = $this->config->getApiKeyWithPrefix('X-ElasticEmail-ApiKey');

        if ($apiKey !== null) {
            $headers['X-ElasticEmail-ApiKey'] = $apiKey;
        }

        $defaultHeaders = [];

        if ($this->config->getUserAgent()) {
            $defaultHeaders['User-Agent'] = $this->config->getUserAgent();
        }

        $headers = array_merge(
            $defaultHeaders,
            $headerParams,
            $headers
        );

        $operationHost = $this->config->getHost();
        $query = ObjectSerializer::buildQuery($queryParams);

        return new Request(
            'POST',
            $operationHost.$resourcePath.($query ? "?{$query}" : ''),
            $headers,
            $httpBody
        );
    }
}
