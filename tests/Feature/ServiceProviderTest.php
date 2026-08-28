<?php

use FabioTech\LaravelElasticEmail\Transport\ElasticEmailTransport;

it('registers an Elastic Email mailer automatically', function () {
    expect(config('mail.mailers.elasticemail'))
        ->toMatchArray([
            'transport' => 'elasticemail',
            'key' => 'test-api-key',
            'connect_timeout' => 5.0,
            'timeout' => 15.0,
            'max_body_bytes' => 2_000_000,
            'max_attachments' => 10,
            'max_attachment_bytes' => 10_000_000,
            'max_message_bytes' => 15_000_000,
            'max_payload_bytes' => 20_000_000,
            'max_recipients' => 1_000,
            'max_header_bytes' => 16_384,
            'max_metadata_bytes' => 262_144,
            'max_response_bytes' => 65_536,
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
