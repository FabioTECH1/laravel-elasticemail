<?php

use ElasticEmail\Api\EmailsApi;
use ElasticEmail\ApiException;
use ElasticEmail\Configuration;
use ElasticEmail\Model\BodyContentType;
use ElasticEmail\Model\EmailSend;
use ElasticEmail\Model\EmailTransactionalMessageData;
use FabioTech\LaravelElasticEmail\Exception\MessageRejectedException;
use FabioTech\LaravelElasticEmail\Transport\ClassifyRequestFailure;
use FabioTech\LaravelElasticEmail\Transport\CompatibilityEmailsApi;
use FabioTech\LaravelElasticEmail\Transport\ElasticEmailTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Mockery\MockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;

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
        emailsApi: new CompatibilityEmailsApi($client, $configuration),
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

it('logs only bounded metadata and exposes only a safe transport exception', function () {
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
                ->failure_kind->toBe('http')
                ->retryable->toBeFalse()
                ->recipient_count->toBe(3)
                ->recipient_counts->toBe(['bcc' => 1, 'cc' => 1, 'to' => 1])
                ->attachment_count->toBe(1)
                ->attachment_bytes->toBe(17);

            expect($context['elapsed_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);

            expect($context)->not->toHaveKeys([
                'from_domain',
                'provider_error',
                'provider_request_id',
                'recipient_domains',
                'reply_to_domain',
            ]);

            expect(json_encode($context, JSON_THROW_ON_ERROR))
                ->not->toContain('test-api-key')
                ->not->toContain('billing.private.test')
                ->not->toContain('reply.private.test')
                ->not->toContain('recipient@example.com')
                ->not->toContain('copy.example.org')
                ->not->toContain('blind.example.net')
                ->not->toContain('secret')
                ->not->toContain('+234 800 123 4567')
                ->not->toContain('Invoice ready')
                ->not->toContain('%PDF-test-content');

            return true;
        }));

    $transport = new ElasticEmailTransport('test-api-key', $emailsApi, $logger);
    $email = (new Email)
        ->from('sender@billing.private.test')
        ->to('recipient@example.com')
        ->cc('copy@copy.example.org')
        ->bcc('blind@blind.example.net')
        ->replyTo('support@reply.private.test')
        ->subject('Invoice ready')
        ->text('Your invoice')
        ->attach('%PDF-test-content', 'invoice-123.pdf', 'application/pdf');

    expect(fn () => $transport->send($email))
        ->toThrow(TransportException::class, 'Elastic Email API request failed with status 400.');
});

it('classifies transient failures without retrying a potentially delivered email', function (int $statusCode, string $failureKind, bool $retryable) {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldReceive('emailsTransactionalPost')
        ->once()
        ->andThrow(new ApiException(code: $statusCode));

    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')
        ->once()
        ->with('Elastic Email API request failed.', Mockery::on(function (array $context) use ($failureKind, $retryable): bool {
            expect($context['failure_kind'])->toBe($failureKind);
            expect($context['retryable'])->toBe($retryable);
            expect($context['elapsed_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);

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
    'unclassified API failure' => [0, 'api', true],
    'request timeout' => [408, 'http', true],
    'rate limited' => [429, 'http', true],
    'server failure' => [503, 'http', true],
    'invalid request' => [400, 'http', false],
]);

it('preserves safe network failure diagnoses through the SDK wrapper', function (string $failureKind, string $exceptionMessage, bool $retryable) {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldReceive('emailsTransactionalPost')
        ->once()
        ->andThrow(new ApiException("[0] [elasticemail-failure:{$failureKind}]"));

    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')
        ->once()
        ->with('Elastic Email API request failed.', Mockery::on(function (array $context) use ($failureKind, $retryable): bool {
            expect($context['failure_kind'])->toBe($failureKind);
            expect($context['retryable'])->toBe($retryable);
            expect($context['status_code'])->toBeNull();
            expect($context['elapsed_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);

            return true;
        }));

    $transport = new ElasticEmailTransport('test-api-key', $emailsApi, $logger);
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Hello');

    expect(fn () => $transport->send($email))
        ->toThrow(TransportException::class, $exceptionMessage);
})->with([
    'timeout' => ['timeout', 'Elastic Email API request timed out before receiving a response.', true],
    'DNS' => ['dns', 'Elastic Email API provider host could not be resolved.', true],
    'TLS' => ['tls', 'Elastic Email API TLS connection failed.', true],
    'connection' => ['connect', 'Elastic Email API connection failed before receiving a response.', true],
    'request' => ['request', 'Elastic Email API request failed before receiving a response.', true],
    'response read' => ['response_read', 'Elastic Email API response could not be read safely.', true],
    'response limit' => ['response_limit', 'Elastic Email API response exceeded the configured byte limit.', false],
]);

it('carries a classified HTTP client failure through the real SDK boundary', function () {
    $handler = function (RequestInterface $request) {
        return Create::rejectionFor(new ConnectException(
            'timeout mentioning recipient@example.com and test-api-key',
            $request,
            handlerContext: ['errno' => 28],
        ));
    };
    $stack = HandlerStack::create($handler);
    $stack->push(new ClassifyRequestFailure);
    $configuration = (new Configuration)
        ->setApiKey('X-ElasticEmail-ApiKey', 'test-api-key');

    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')
        ->once()
        ->with('Elastic Email API request failed.', Mockery::on(function (array $context): bool {
            expect($context['failure_kind'])->toBe('timeout');
            expect($context['retryable'])->toBeTrue();
            expect(json_encode($context, JSON_THROW_ON_ERROR))
                ->not->toContain('recipient@example.com')
                ->not->toContain('test-api-key');

            return true;
        }));

    $transport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: new CompatibilityEmailsApi(new Client(['handler' => $stack]), $configuration),
        logger: $logger,
    );
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Hello');

    expect(fn () => $transport->send($email))
        ->toThrow(TransportException::class, 'Elastic Email API request timed out before receiving a response.');
});

it('treats a successful HTTP status with an invalid provider response as permanent', function () {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldReceive('emailsTransactionalPost')
        ->once()
        ->andThrow(new ApiException('provider-controlled response', 200));

    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')
        ->once()
        ->with('Elastic Email API request failed.', Mockery::on(function (array $context): bool {
            expect($context['failure_kind'])->toBe('provider_response');
            expect($context['retryable'])->toBeFalse();

            return true;
        }));

    $transport = new ElasticEmailTransport('test-api-key', $emailsApi, $logger);
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Hello');

    expect(fn () => $transport->send($email))
        ->toThrow(TransportException::class, 'Elastic Email API returned an invalid response with status 200.');
});

it('accepts bodies at the byte limit and rejects the next byte before calling the API', function () {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldReceive('emailsTransactionalPost')
        ->once()
        ->andReturn(new EmailSend(['message_id' => 'accepted']));

    $transport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: $emailsApi,
        maxBodyBytes: 5,
        maxAttachmentBytes: 5,
        maxMessageBytes: 5,
    );

    $accepted = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->text('12345');

    $transport->send($accepted);

    $rejectedApi = Mockery::mock(EmailsApi::class);
    $rejectedApi->shouldNotReceive('emailsTransactionalPost');
    $rejectingTransport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: $rejectedApi,
        maxBodyBytes: 5,
        maxAttachmentBytes: 5,
        maxMessageBytes: 5,
    );
    $rejected = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->text('123456');

    expect(fn () => $rejectingTransport->send($rejected))
        ->toThrow(MessageRejectedException::class, 'Email body exceeds the configured byte limit.');
});

it('reads seekable body streams with a finite limit', function () {
    $body = fopen('php://temp', 'w+');
    fwrite($body, 'stream body');

    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldReceive('emailsTransactionalPost')
        ->once()
        ->with(Mockery::on(function (EmailTransactionalMessageData $payload): bool {
            expect($payload->getContent()->getBody()[0]->getContent())->toBe('stream body');

            return true;
        }))
        ->andReturn(new EmailSend(['message_id' => 'accepted']));

    $transport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: $emailsApi,
        maxBodyBytes: 11,
        maxAttachmentBytes: 11,
        maxMessageBytes: 11,
    );
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->text($body);

    $transport->send($email);
    fclose($body);
});

it('rejects non-seekable body streams and raw MIME messages before the API call', function () {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldNotReceive('emailsTransactionalPost');
    $transport = new ElasticEmailTransport('test-api-key', $emailsApi);

    [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    fwrite($write, 'stream body');
    fclose($write);

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->text($read);

    expect(fn () => $transport->send($email))
        ->toThrow(MessageRejectedException::class, 'Email body streams must be local and seekable.');
    fclose($read);

    $rawMessage = new Message;
    $rawMessage->getHeaders()->addMailboxListHeader('From', [new Address('sender@example.com')]);
    $rawMessage->getHeaders()->addMailboxListHeader('To', [new Address('recipient@example.com')]);
    $rawMessage->setBody(new TextPart('body'));

    expect(fn () => $transport->send($rawMessage))
        ->toThrow(MessageRejectedException::class, 'Elastic Email transport accepts Symfony Email messages only.');
});

it('preserves binary attachments while enforcing per-part and aggregate limits', function () {
    $binary = "\x00\x01\xffab";

    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldReceive('emailsTransactionalPost')
        ->once()
        ->with(Mockery::on(function (EmailTransactionalMessageData $payload) use ($binary): bool {
            $attachment = $payload->getContent()->getAttachments()[0];
            expect($attachment->getBinaryContent())->toBe(base64_encode($binary));
            expect($attachment->getSize())->toBe(strlen($binary));

            return true;
        }))
        ->andReturn(new EmailSend(['message_id' => 'accepted']));

    $transport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: $emailsApi,
        maxBodyBytes: 5,
        maxAttachmentBytes: 5,
        maxMessageBytes: 5,
    );
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->attach($binary, 'binary.dat');

    $transport->send($email);

    $rejectedApi = Mockery::mock(EmailsApi::class);
    $rejectedApi->shouldNotReceive('emailsTransactionalPost');
    $rejectingTransport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: $rejectedApi,
        maxBodyBytes: 5,
        maxAttachmentBytes: 5,
        maxMessageBytes: 5,
    );
    $tooLarge = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->attach($binary.'x', 'binary.dat');

    expect(fn () => $rejectingTransport->send($tooLarge))
        ->toThrow(MessageRejectedException::class, 'Email attachment exceeds the configured byte limit.');
});

it('supports bounded file, resource, and inline attachments', function () {
    $path = tempnam(sys_get_temp_dir(), 'elasticemail-test-');
    file_put_contents($path, 'file-data');
    $resource = fopen('php://temp', 'w+');
    fwrite($resource, 'resource-data');

    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldReceive('emailsTransactionalPost')
        ->once()
        ->with(Mockery::on(function (EmailTransactionalMessageData $payload): bool {
            $attachments = $payload->getContent()->getAttachments();

            expect($attachments)->toHaveCount(3);
            expect($attachments[0]->getName())->toBe('file.txt');
            expect($attachments[0]->getBinaryContent())->toBe(base64_encode('file-data'));
            expect($attachments[1]->getName())->toBe('resource.txt');
            expect($attachments[1]->getBinaryContent())->toBe(base64_encode('resource-data'));
            expect($attachments[2]->getName())->toBe('inline.txt');
            expect($attachments[2]->getBinaryContent())->toBe(base64_encode('inline-data'));

            return true;
        }))
        ->andReturn(new EmailSend(['message_id' => 'accepted']));

    $transport = new ElasticEmailTransport('test-api-key', $emailsApi);
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->attachFromPath($path, 'file.txt')
        ->attach($resource, 'resource.txt')
        ->embed('inline-data', 'inline.txt');

    try {
        $transport->send($email);
    } finally {
        fclose($resource);
        unlink($path);
    }
});

it('rejects custom non-base64 attachment encodings', function () {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldNotReceive('emailsTransactionalPost');
    $transport = new ElasticEmailTransport('test-api-key', $emailsApi);
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->addPart(new DataPart('attachment', 'a.txt', 'text/plain', '8bit'));

    expect(fn () => $transport->send($email))
        ->toThrow(MessageRejectedException::class, 'Email attachments must use base64 transfer encoding.');
});

it('rejects cumulative content, attachment count, recipient count, and encoded payload overflow', function (array $limits, Closure $message, string $expectedMessage) {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldNotReceive('emailsTransactionalPost');

    $transport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: $emailsApi,
        maxBodyBytes: $limits['max_body_bytes'] ?? 10,
        maxAttachments: $limits['max_attachments'] ?? 10,
        maxAttachmentBytes: $limits['max_attachment_bytes'] ?? 10,
        maxMessageBytes: $limits['max_message_bytes'] ?? 20,
        maxPayloadBytes: $limits['max_payload_bytes'] ?? 20_000_000,
        maxRecipients: $limits['max_recipients'] ?? 1_000,
        maxHeaderBytes: $limits['max_header_bytes'] ?? 16_384,
        maxMetadataBytes: $limits['max_metadata_bytes'] ?? 262_144,
    );

    expect(fn () => $transport->send($message()))
        ->toThrow(MessageRejectedException::class, $expectedMessage);
})->with([
    'aggregate bytes' => [
        ['max_body_bytes' => 4, 'max_attachment_bytes' => 4, 'max_message_bytes' => 5],
        fn () => (new Email)->from('sender@example.com')->to('recipient@example.com')->text('123')->attach('456', 'a.txt'),
        'Email content exceeds the configured aggregate byte limit.',
    ],
    'attachment count' => [
        ['max_attachments' => 1],
        fn () => (new Email)->from('sender@example.com')->to('recipient@example.com')->attach('', 'a.txt')->attach('', 'b.txt'),
        'Email has more attachments than the configured limit.',
    ],
    'recipient count' => [
        ['max_recipients' => 1],
        fn () => (new Email)->from('sender@example.com')->to('one@example.com', 'two@example.com')->text('body'),
        'Email has more addresses than the configured limit.',
    ],
    'encoded payload' => [
        ['max_payload_bytes' => 128, 'max_header_bytes' => 64, 'max_metadata_bytes' => 128],
        fn () => (new Email)->from('sender@example.com')->to('recipient@example.com')->text('small'),
        'Email payload exceeds the configured encoded byte limit.',
    ],
    'header bytes' => [
        ['max_header_bytes' => 8, 'max_metadata_bytes' => 64],
        fn () => (new Email)->from('a@b.co')->to('c@d.co')->subject('123456789')->text('body'),
        'Email subject exceeds the configured header byte limit.',
    ],
    'aggregate metadata bytes' => [
        ['max_header_bytes' => 20, 'max_metadata_bytes' => 20],
        fn () => (new Email)->from('sender@example.com')->to('recipient@example.com')->text('body'),
        'Email metadata exceeds the configured aggregate byte limit.',
    ],
]);

it('includes reply-to and from addresses in the address-count limit', function () {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldNotReceive('emailsTransactionalPost');
    $transport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: $emailsApi,
        maxRecipients: 3,
    );
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->replyTo('one@example.com', 'two@example.com')
        ->text('body');

    expect(fn () => $transport->send($email))
        ->toThrow(MessageRejectedException::class, 'Email has more addresses than the configured limit.');
});

it('rejects unsafe hard-limit values during direct transport construction', function (array $arguments, string $message) {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);

    expect(fn () => new ElasticEmailTransport(
        ...[
            'key' => 'test-api-key',
            'emailsApi' => $emailsApi,
            ...$arguments,
        ],
    ))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'attachment count' => [
        ['maxAttachments' => 101],
        'Elastic Email maxAttachments exceeds the supported hard limit.',
    ],
    'recipient count' => [
        ['maxRecipients' => 10_001],
        'Elastic Email maxRecipients exceeds the supported hard limit.',
    ],
    'message bytes' => [
        ['maxMessageBytes' => 20_000_001],
        'Elastic Email maxMessageBytes exceeds the supported hard limit.',
    ],
    'header bytes' => [
        ['maxHeaderBytes' => 65_537],
        'Elastic Email maxHeaderBytes exceeds the supported hard limit.',
    ],
    'metadata bytes' => [
        ['maxMetadataBytes' => 1_000_001],
        'Elastic Email maxMetadataBytes exceeds the supported hard limit.',
    ],
]);

it('rejects JSON escape amplification before the API serializes the payload', function () {
    /** @var EmailsApi&MockInterface $emailsApi */
    $emailsApi = Mockery::mock(EmailsApi::class);
    $emailsApi->shouldNotReceive('emailsTransactionalPost');
    $transport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: $emailsApi,
        maxBodyBytes: 4_000_000,
        maxAttachmentBytes: 4_000_000,
        maxMessageBytes: 4_000_000,
        maxPayloadBytes: 20_000_000,
    );
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->text(str_repeat("\x01", 3_400_000));

    expect(fn () => $transport->send($email))
        ->toThrow(MessageRejectedException::class, 'Email payload exceeds the configured encoded byte limit.');
});

it('does not emit a deprecation warning when serializing a transactional request payload', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'MessageID' => 'provider-message-id',
            'TransactionID' => 'provider-transaction-id',
        ], JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $configuration = (new Configuration)->setApiKey('X-ElasticEmail-ApiKey', 'test-api-key');

    $transport = new ElasticEmailTransport(
        key: 'test-api-key',
        emailsApi: new CompatibilityEmailsApi($client, $configuration),
    );

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Deprecation Test')
        ->text('Ensuring no jsonEncode deprecation is emitted.');

    $deprecations = [];
    set_error_handler(function (int $errno, string $errstr) use (&$deprecations): bool {
        if ($errno === E_USER_DEPRECATED) {
            $deprecations[] = $errstr;
        }

        return true;
    }, E_USER_DEPRECATED);

    try {
        $transport->send($email);
    } finally {
        restore_error_handler();
    }

    expect($deprecations)->toBeEmpty();
});
