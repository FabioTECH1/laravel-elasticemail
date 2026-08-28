<?php

use ElasticEmail\Api\EmailsApi;
use ElasticEmail\ApiException;
use ElasticEmail\Configuration;
use ElasticEmail\Model\BodyContentType;
use ElasticEmail\Model\EmailSend;
use ElasticEmail\Model\EmailTransactionalMessageData;
use FabioTech\LaravelElasticEmail\Transport\ElasticEmailTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

it('uses the SDK transactional endpoint over HTTP with explicit timeouts', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'MessageID' => 'provider-message-id',
            'TransactionID' => 'provider-transaction-id',
        ], JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client([
        'connect_timeout' => 4.0,
        'handler' => HandlerStack::create($mock),
        'timeout' => 12.0,
    ]);
    $configuration = (new Configuration)
        ->setApiKey('X-ElasticEmail-ApiKey', 'test-api-key');
    $transport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: new EmailsApi($client, $configuration),
    );

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Hello');

    $sentMessage = $transport->send($email);
    $request = $mock->getLastRequest();
    $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($sentMessage->getMessageId())->toBe('provider-message-id');
    expect($request->getUri()->__toString())->toBe('https://api.elasticemail.com/v4/emails/transactional');
    expect($request->getHeaderLine('X-ElasticEmail-ApiKey'))->toBe('test-api-key');
    expect($request->getHeaderLine('Content-Type'))->toBe('application/json');
    expect($mock->getLastOptions()['timeout'])->toBe(12.0);
    expect($mock->getLastOptions()['connect_timeout'])->toBe(4.0);
    expect($payload['Recipients']['To'])->toBe(['recipient@example.com']);
    expect($payload['Recipients'])->not->toHaveKeys(['CC', 'BCC']);
    expect($payload['Content']['Body'][0]['ContentType'])->toBe('PlainText');
    expect($payload['Content'])->not->toHaveKey('Attachments');
});

it('maps Laravel email content, recipients, and attachments to SDK models', function () {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldReceive('emailsTransactionalPost')
        ->once()
        ->with(Mockery::on(function (EmailTransactionalMessageData $payload): bool {
            $recipients = $payload->getRecipients();
            $content = $payload->getContent();
            $body = $content->getBody();
            $attachment = $content->getAttachments()[0];

            expect($recipients->getTo())->toBe(['"Primary Recipient" <recipient@example.com>']);
            expect($recipients->getCc())->toBe(['"Copy Recipient" <copy@example.org>']);
            expect($recipients->getBcc())->toBe(['blind@example.net']);
            expect($content->getSubject())->toBe('Invoice ready');
            expect($content->getFrom())->toBe('"Example Billing" <billing@example.com>');
            expect($content->getEnvelopeFrom())->toBe('billing@example.com');
            expect($content->getReplyTo())->toBe('"Support" <support@example.com>');
            expect($body)->toHaveCount(2);
            expect($body[0]->getContentType())->toBe(BodyContentType::HTML);
            expect($body[0]->getContent())->toBe('<h1>Your invoice</h1>');
            expect($body[1]->getContentType())->toBe(BodyContentType::PLAIN_TEXT);
            expect($body[1]->getContent())->toBe('Your invoice');
            expect($attachment->getName())->toBe('invoice-123.pdf');
            expect($attachment->getContentType())->toBe('application/pdf');
            expect($attachment->getBinaryContent())->toBe(base64_encode('%PDF-test-content'));
            expect($attachment->getSize())->toBe(strlen('%PDF-test-content'));

            return true;
        }))
        ->andReturn(new EmailSend(['message_id' => 'provider-message-id']));

    $transport = new ElasticEmailTransport('test-api-key', $emailsApi);
    $email = (new Email)
        ->from(new Address('billing@example.com', 'Example Billing'))
        ->to(new Address('recipient@example.com', 'Primary Recipient'))
        ->cc(new Address('copy@example.org', 'Copy Recipient'))
        ->bcc('blind@example.net')
        ->replyTo(new Address('support@example.com', 'Support'))
        ->subject('Invoice ready')
        ->html('<h1>Your invoice</h1>')
        ->text('Your invoice')
        ->attach('%PDF-test-content', 'invoice-123.pdf', 'application/pdf');

    $transport->send($email);
});

it('logs redacted diagnostics and exposes only a safe transport exception', function () {
    $apiException = new ApiException(
        message: '[400] provider request failed for recipient@example.com',
        code: 400,
        responseHeaders: ['X-Request-Id' => ['elastic-request-123']],
        responseBody: json_encode([
            'Error' => 'Recipient recipient@example.com and phone +234 800 123 4567 are invalid. See https://example.com?token=secret using test-api-key.',
        ], JSON_THROW_ON_ERROR),
    );

    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldReceive('emailsTransactionalPost')->once()->andThrow($apiException);

    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')
        ->once()
        ->with('Elastic Email API request failed.', Mockery::on(function (array $context): bool {
            expect($context)
                ->status_code->toBe(400)
                ->provider_error->toBe('Recipient [redacted-email] and phone [redacted-phone] are invalid. See [redacted-url] using [redacted-api-key].')
                ->provider_request_id->toBe('elastic-request-123')
                ->retryable->toBeFalse()
                ->recipient_count->toBe(3)
                ->recipient_counts->toBe(['bcc' => 1, 'cc' => 1, 'to' => 1])
                ->attachment_count->toBe(1)
                ->attachment_bytes->toBe(17);

            expect(json_encode($context, JSON_THROW_ON_ERROR))
                ->not->toContain('test-api-key')
                ->not->toContain('recipient@example.com')
                ->not->toContain('secret')
                ->not->toContain('+234 800 123 4567')
                ->not->toContain('Invoice ready')
                ->not->toContain('%PDF-test-content');

            return true;
        }));

    $transport = new ElasticEmailTransport('test-api-key', $emailsApi, $logger);
    $email = (new Email)
        ->from('billing@example.com')
        ->to('recipient@example.com')
        ->cc('copy@example.org')
        ->bcc('blind@example.net')
        ->subject('Invoice ready')
        ->text('Your invoice')
        ->attach('%PDF-test-content', 'invoice-123.pdf', 'application/pdf');

    expect(fn () => $transport->send($email))
        ->toThrow(TransportException::class, 'Elastic Email API request failed with status 400.');
});

it('classifies transient failures without retrying a potentially delivered email', function (int $statusCode, bool $retryable) {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldReceive('emailsTransactionalPost')
        ->once()
        ->andThrow(new ApiException(code: $statusCode));

    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')
        ->once()
        ->with('Elastic Email API request failed.', Mockery::on(function (array $context) use ($retryable): bool {
            expect($context['retryable'])->toBe($retryable);

            return true;
        }));

    $transport = new ElasticEmailTransport('test-api-key', $emailsApi, $logger);
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Hello');

    expect(fn () => $transport->send($email))->toThrow(TransportException::class);
})->with([
    'connection failure' => [0, true],
    'request timeout' => [408, true],
    'rate limited' => [429, true],
    'server failure' => [503, true],
    'invalid request' => [400, false],
]);
