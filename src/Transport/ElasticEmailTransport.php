<?php

namespace Dataphyte\LaravelElasticEmail\Transport;

use ElasticEmail\Api\EmailsApi;
use ElasticEmail\ApiException;
use ElasticEmail\Model\BodyContentType;
use ElasticEmail\Model\BodyPart;
use ElasticEmail\Model\EmailContent;
use ElasticEmail\Model\EmailSend;
use ElasticEmail\Model\EmailTransactionalMessageData;
use ElasticEmail\Model\MessageAttachment;
use ElasticEmail\Model\TransactionalRecipient;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

final class ElasticEmailTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $key,
        private readonly EmailsApi $emailsApi,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct(logger: $logger);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $payload = $this->getPayload($email, $message);

        try {
            /** @var EmailSend $response */
            $response = $this->emailsApi->emailsTransactionalPost($payload);
            $providerMessageId = $response->getMessageId() ?: $response->getTransactionId();

            if ($providerMessageId) {
                $message->setMessageId($providerMessageId);
            }
        } catch (ApiException $exception) {
            $statusCode = (int) $exception->getCode();

            $this->getLogger()->error('Elastic Email API request failed.', [
                ...$this->emailContext($email, $payload),
                'exception_class' => $exception::class,
                'provider_error' => $this->providerError($exception),
                'provider_request_id' => $this->providerRequestId($exception),
                'retryable' => $this->isRetryable($statusCode),
                'status_code' => $statusCode ?: null,
            ]);

            $exceptionMessage = $statusCode > 0
                ? "Elastic Email API request failed with status {$statusCode}."
                : 'Elastic Email API request failed before receiving a response.';

            throw new TransportException($exceptionMessage, $statusCode);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emailContext(Email $email, EmailTransactionalMessageData $payload): array
    {
        $content = $payload->getContent();
        $body = $content->getBody() ?? [];
        $attachments = $content->getAttachments() ?? [];
        $recipients = [
            ...$email->getTo(),
            ...$email->getCc(),
            ...$email->getBcc(),
        ];

        return [
            'attachment_bytes' => array_sum(array_map(
                fn (MessageAttachment $attachment): int => (int) $attachment->getSize(),
                $attachments,
            )),
            'attachment_count' => count($attachments),
            'body_lengths' => array_map(
                fn (BodyPart $bodyPart): int => Str::length((string) $bodyPart->getContent()),
                $body,
            ),
            'body_types' => array_map(
                fn (BodyPart $bodyPart): string => (string) $bodyPart->getContentType(),
                $body,
            ),
            'from_domain' => $this->addressDomain($email->getFrom()[0] ?? null),
            'recipient_count' => count($recipients),
            'recipient_counts' => [
                'bcc' => count($email->getBcc()),
                'cc' => count($email->getCc()),
                'to' => count($email->getTo()),
            ],
            'recipient_domains' => array_values(array_unique(array_map(
                fn (Address $address): string => $this->addressDomain($address),
                $recipients,
            ))),
            'reply_to_domain' => $this->addressDomain($email->getReplyTo()[0] ?? null),
            'subject_length' => Str::length((string) $email->getSubject()),
        ];
    }

    private function addressDomain(?Address $address): ?string
    {
        if ($address === null) {
            return null;
        }

        return Str::of($address->getAddress())->afterLast('@')->lower()->toString();
    }

    private function providerError(ApiException $exception): ?string
    {
        $responseBody = $exception->getResponseBody();

        if (is_string($responseBody)) {
            $responseBody = json_decode($responseBody, true);
        } elseif (is_object($responseBody)) {
            $responseBody = get_object_vars($responseBody);
        }

        if (! is_array($responseBody)) {
            return null;
        }

        foreach (['Error', 'error', 'Message', 'message'] as $key) {
            if (isset($responseBody[$key]) && is_scalar($responseBody[$key])) {
                return $this->redactProviderError((string) $responseBody[$key]);
            }
        }

        return null;
    }

    private function redactProviderError(string $message): string
    {
        return Str::of($message)
            ->replace($this->key, '[redacted-api-key]')
            ->replaceMatches('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[redacted-email]')
            ->replaceMatches('/https?:\/\/[^\s"\']+/iu', '[redacted-url]')
            ->replaceMatches('/\+?[0-9][0-9() .\-]{7,}[0-9]/u', '[redacted-phone]')
            ->replaceMatches('/\b[A-Za-z0-9_\-]{32,}\b/u', '[redacted-token]')
            ->limit(2_000)
            ->toString();
    }

    private function providerRequestId(ApiException $exception): ?string
    {
        $headers = $exception->getResponseHeaders() ?? [];

        foreach (['x-elasticemail-request-id', 'x-request-id', 'x-correlation-id', 'traceparent'] as $expectedHeader) {
            foreach ($headers as $header => $values) {
                if (Str::lower((string) $header) !== $expectedHeader) {
                    continue;
                }

                $requestId = is_array($values) ? implode(',', $values) : (string) $values;

                return Str::limit($requestId, 255, '');
            }
        }

        return null;
    }

    private function isRetryable(int $statusCode): bool
    {
        return $statusCode === 0
            || in_array($statusCode, [408, 425, 429], true)
            || $statusCode >= 500;
    }

    private function getPayload(Email $email, SentMessage $message): EmailTransactionalMessageData
    {
        $attachments = array_map(
            fn (DataPart $attachment): MessageAttachment => $this->getAttachment($attachment),
            $email->getAttachments(),
        );
        $body = $this->getBody($email);
        $replyTo = $this->getReplyTo($email);
        $contentData = [
            'envelope_from' => $message->getEnvelope()->getSender()->getAddress(),
            'from' => $email->getFrom()[0]->toString(),
            'subject' => $email->getSubject(),
        ];

        if ($attachments !== []) {
            $contentData['attachments'] = $attachments;
        }

        if ($body !== []) {
            $contentData['body'] = $body;
        }

        if ($replyTo !== null) {
            $contentData['reply_to'] = $replyTo;
        }

        $recipientData = ['to' => $this->getAddresses($email->getTo())];
        $cc = $this->getAddresses($email->getCc());
        $bcc = $this->getAddresses($email->getBcc());

        if ($cc !== []) {
            $recipientData['cc'] = $cc;
        }

        if ($bcc !== []) {
            $recipientData['bcc'] = $bcc;
        }

        return new EmailTransactionalMessageData([
            'content' => new EmailContent($contentData),
            'recipients' => new TransactionalRecipient($recipientData),
        ]);
    }

    /**
     * @return list<BodyPart>
     */
    private function getBody(Email $email): array
    {
        $body = [];

        if ($email->getHtmlBody() !== null) {
            $body[] = new BodyPart([
                'charset' => $email->getHtmlCharset(),
                'content' => $this->bodyToString($email->getHtmlBody()),
                'content_type' => BodyContentType::HTML,
            ]);
        }

        if ($email->getTextBody() !== null) {
            $body[] = new BodyPart([
                'charset' => $email->getTextCharset(),
                'content' => $this->bodyToString($email->getTextBody()),
                'content_type' => BodyContentType::PLAIN_TEXT,
            ]);
        }

        return $body;
    }

    /**
     * @param  resource|string  $body
     */
    private function bodyToString($body): string
    {
        if (! is_resource($body)) {
            return $body;
        }

        $metadata = stream_get_meta_data($body);

        if (($metadata['seekable'] ?? false) === true) {
            rewind($body);
        }

        $contents = stream_get_contents($body);

        if ($contents === false) {
            throw new TransportException('Unable to read an email body stream.');
        }

        return $contents;
    }

    private function getAttachment(DataPart $attachment): MessageAttachment
    {
        $contents = $attachment->getBody();

        return new MessageAttachment([
            'binary_content' => base64_encode($contents),
            'content_type' => $attachment->getContentType(),
            'name' => $attachment->getFilename() ?? 'attachment',
            'size' => strlen($contents),
        ]);
    }

    private function getReplyTo(Email $email): ?string
    {
        $replyTo = $this->getAddresses($email->getReplyTo());

        return $replyTo === [] ? null : implode(', ', $replyTo);
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<string>
     */
    private function getAddresses(array $addresses): array
    {
        return array_map(
            fn (Address $address): string => $address->toString(),
            $addresses,
        );
    }

    public function __toString(): string
    {
        return 'elasticemail';
    }
}
