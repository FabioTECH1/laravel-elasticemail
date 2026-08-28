<?php

use Dataphyte\LaravelElasticEmail\Transport\ElasticEmailTransport;
use Dataphyte\LaravelElasticEmail\Transport\ElasticEmailTransportFactory;

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
]);
