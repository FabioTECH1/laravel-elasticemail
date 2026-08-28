<?php

use FabioTech\LaravelElasticEmail\Transport\ElasticEmailTransport;

it('registers an Elastic Email mailer automatically', function () {
    expect(config('mail.mailers.elasticemail'))
        ->toMatchArray([
            'transport' => 'elasticemail',
            'key' => 'test-api-key',
            'connect_timeout' => 5.0,
            'timeout' => 15.0,
        ]);

    expect(app('mail.manager')->mailer('elasticemail')->getSymfonyTransport())
        ->toBeInstanceOf(ElasticEmailTransport::class);
});

it('allows application mail configuration to override package defaults', function () {
    config()->set('mail.mailers.elasticemail.timeout', 9.0);
    app('mail.manager')->forgetMailers();

    $mailer = app('mail.manager')->mailer('elasticemail');

    expect($mailer->getSymfonyTransport())->toBeInstanceOf(ElasticEmailTransport::class);
    expect(config('mail.mailers.elasticemail.timeout'))->toBe(9.0);
});
