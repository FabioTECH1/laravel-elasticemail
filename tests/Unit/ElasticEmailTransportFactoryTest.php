<?php

use FabioTech\LaravelElasticEmail\Exception\ClassifiedRequestException;
use FabioTech\LaravelElasticEmail\Transport\ClassifyRequestFailure;
use FabioTech\LaravelElasticEmail\Transport\CompatibilityEmailsApi;
use FabioTech\LaravelElasticEmail\Transport\ElasticEmailTransport;
use FabioTech\LaravelElasticEmail\Transport\ElasticEmailTransportFactory;
use FabioTech\LaravelElasticEmail\Transport\LimitResponseBody;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

it('creates the transport with valid configuration', function () {
    $transport = (new ElasticEmailTransportFactory)->make([
        'key' => 'test-api-key',
        'connect_timeout' => 5,
        'timeout' => 15,
    ]);

    expect($transport)->toBeInstanceOf(ElasticEmailTransport::class);
});

it('rejects invalid transport configuration', function (array $config, string $message) {
    expect(fn () => (new ElasticEmailTransportFactory)->make($config))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing API key' => [
        ['connect_timeout' => 5, 'timeout' => 15],
        'Elastic Email API key is not configured.',
    ],
    'empty API key' => [
        ['key' => '  ', 'connect_timeout' => 5, 'timeout' => 15],
        'Elastic Email API key is not configured.',
    ],
    'invalid connection timeout' => [
        ['key' => 'test', 'connect_timeout' => 0, 'timeout' => 15],
        'Elastic Email connection timeout must be greater than zero.',
    ],
    'invalid request timeout' => [
        ['key' => 'test', 'connect_timeout' => 5, 'timeout' => 0],
        'Elastic Email request timeout must be greater than zero.',
    ],
    'malformed timeout string' => [
        ['key' => 'test', 'timeout' => '5junk'],
        'Elastic Email request timeout must be a positive decimal number.',
    ],
    'boolean timeout' => [
        ['key' => 'test', 'timeout' => true],
        'Elastic Email request timeout must be a positive decimal number.',
    ],
    'array timeout' => [
        ['key' => 'test', 'timeout' => [15]],
        'Elastic Email request timeout must be a positive decimal number.',
    ],
    'excessive connection timeout' => [
        ['key' => 'test', 'connect_timeout' => 61, 'timeout' => 15],
        'Elastic Email connection timeout cannot exceed 60 seconds.',
    ],
    'excessive request timeout' => [
        ['key' => 'test', 'connect_timeout' => 5, 'timeout' => 601],
        'Elastic Email request timeout cannot exceed 600 seconds.',
    ],
    'non-integer limit' => [
        ['key' => 'test', 'max_response_bytes' => '1.5'],
        'Elastic Email max_response_bytes must be a positive integer.',
    ],
    'scientific notation limit' => [
        ['key' => 'test', 'max_response_bytes' => '1e3'],
        'Elastic Email max_response_bytes must be a positive integer.',
    ],
    'oversized numeric string limit' => [
        ['key' => 'test', 'max_response_bytes' => '999999999999999999999999999999999999'],
        'Elastic Email max_response_bytes must be a positive integer.',
    ],
    'response limit exceeds hard limit' => [
        ['key' => 'test', 'max_response_bytes' => 1_000_001],
        'Elastic Email max_response_bytes exceeds the supported hard limit of 1000000.',
    ],
    'attachment count exceeds hard limit' => [
        ['key' => 'test', 'max_attachments' => 101],
        'Elastic Email max_attachments exceeds the supported hard limit of 100.',
    ],
    'body limit exceeds aggregate limit' => [
        ['key' => 'test', 'max_body_bytes' => 11, 'max_message_bytes' => 10],
        'Elastic Email body byte limit cannot exceed the message byte limit.',
    ],
    'attachment limit exceeds aggregate limit' => [
        ['key' => 'test', 'max_body_bytes' => 10, 'max_attachment_bytes' => 11, 'max_message_bytes' => 10],
        'Elastic Email attachment byte limit cannot exceed the message byte limit.',
    ],
    'payload exceeds provider limit' => [
        ['key' => 'test', 'max_payload_bytes' => 20_000_001],
        'Elastic Email max_payload_bytes exceeds the supported hard limit of 20000000.',
    ],
    'header limit exceeds metadata limit' => [
        ['key' => 'test', 'max_header_bytes' => 11, 'max_metadata_bytes' => 10],
        'Elastic Email header byte limit cannot exceed the metadata byte limit.',
    ],
    'metadata limit exceeds payload limit' => [
        ['key' => 'test', 'max_header_bytes' => 10, 'max_metadata_bytes' => 11, 'max_payload_bytes' => 10],
        'Elastic Email metadata byte limit cannot exceed the payload byte limit.',
    ],
]);

it('accepts canonical decimal strings from environment configuration', function () {
    $transport = (new ElasticEmailTransportFactory)->make([
        'key' => 'test-api-key',
        'max_attachments' => '10',
        'max_response_bytes' => '65536',
    ]);

    expect($transport)->toBeInstanceOf(ElasticEmailTransport::class);
});

it('bounds actual response bytes with or without a content length', function (Response $response, bool $accepted) {
    $handler = HandlerStack::create(new MockHandler([$response]));
    $handler->push(new LimitResponseBody(5));
    $client = new Client(['handler' => $handler]);

    if ($accepted) {
        expect((string) $client->get('https://api.elasticemail.com/test')->getBody())->toBe('12345');

        return;
    }

    expect(fn () => $client->get('https://api.elasticemail.com/test'))
        ->toThrow(RequestException::class, 'Elastic Email API response exceeded the configured byte limit.');
})->with([
    'exact limit' => [new Response(200, [], '12345'), true],
    'actual bytes exceed limit' => [new Response(200, [], '123456'), false],
    'declared bytes exceed limit' => [new Response(200, ['Content-Length' => '6'], '1'), false],
    'lying content length' => [new Response(200, ['Content-Length' => '1'], '123456'), false],
]);

it('installs a fresh bounded sink before the HTTP handler receives response chunks', function () {
    $sinks = [];
    $handler = function (RequestInterface $request, array $options) use (&$sinks) {
        $sink = $options['sink'];
        $sinks[] = $sink;
        $sink->write('12');
        $sink->write('345');
        $sink->rewind();

        return Create::promiseFor(new Response(200, [], $sink));
    };
    $stack = HandlerStack::create($handler);
    $stack->push(new LimitResponseBody(5));
    $client = new Client(['handler' => $stack]);

    expect((string) $client->get('https://api.elasticemail.com/first')->getBody())->toBe('12345');
    expect((string) $client->get('https://api.elasticemail.com/second')->getBody())->toBe('12345');
    expect($sinks)->toHaveCount(2);
    expect($sinks[0])->not->toBe($sinks[1]);
});

it('stops chunked response writes before they can exceed the sink limit', function () {
    $handler = function (RequestInterface $request, array $options) {
        $options['sink']->write('123');
        $options['sink']->write('456');

        return Create::promiseFor(new Response(200));
    };
    $stack = HandlerStack::create($handler);
    $stack->push(new LimitResponseBody(5));
    $client = new Client(['handler' => $stack]);

    expect(fn () => $client->get('https://api.elasticemail.com/test'))
        ->toThrow(RequestException::class, 'Elastic Email API response exceeded the configured byte limit.');
});

it('classifies connection failures without retaining unsafe exception text', function (int|string|null $errorNumber, string $failureKind) {
    $request = new Request('POST', 'https://api.elasticemail.com/secret?token=unsafe');
    $handler = fn () => Create::rejectionFor(new ConnectException(
        'cURL failure containing recipient@example.com and test-api-key',
        $request,
        handlerContext: ['errno' => $errorNumber],
    ));
    $stack = HandlerStack::create($handler);
    $stack->push(new ClassifyRequestFailure);
    $client = new Client(['handler' => $stack]);

    try {
        $client->send($request);
        test()->fail('Expected a classified request exception.');
    } catch (ClassifiedRequestException $exception) {
        expect($exception->failureKind())->toBe($failureKind);
        expect($exception->getMessage())
            ->toContain("[elasticemail-failure:{$failureKind}]")
            ->not->toContain('recipient@example.com')
            ->not->toContain('test-api-key')
            ->not->toContain('token=unsafe');
    }
})->with([
    'timeout' => [28, 'timeout'],
    'DNS integer' => [6, 'dns'],
    'DNS numeric string' => ['5', 'dns'],
    'TLS' => [60, 'tls'],
    'connection refused' => [7, 'connect'],
    'missing cURL context' => [null, 'connect'],
]);

it('classifies generic request failures and preserves their HTTP response', function () {
    $request = new Request('POST', 'https://api.elasticemail.com/test');
    $response = new Response(503);
    $handler = fn () => Create::rejectionFor(new RequestException(
        'unsafe provider response',
        $request,
        $response,
    ));
    $stack = HandlerStack::create($handler);
    $stack->push(new ClassifyRequestFailure);
    $client = new Client(['handler' => $stack, 'http_errors' => false]);

    try {
        $client->send($request);
        test()->fail('Expected a classified request exception.');
    } catch (ClassifiedRequestException $exception) {
        expect($exception->failureKind())->toBe('request');
        expect($exception->getResponse())->toBe($response);
        expect($exception->getCode())->toBe(503);
        expect($exception->getMessage())
            ->toContain('[elasticemail-failure:request]')
            ->not->toContain('unsafe provider response');
    }
});

it('wires the compatibility API implementation to prevent Guzzle deprecations', function () {
    $factory = new ElasticEmailTransportFactory;
    $transport = $factory->make(['key' => 'test-api-key']);

    $reflection = new ReflectionClass($transport);
    $property = $reflection->getProperty('emailsApi');
    $property->setAccessible(true);

    expect($property->getValue($transport))->toBeInstanceOf(CompatibilityEmailsApi::class);
});
