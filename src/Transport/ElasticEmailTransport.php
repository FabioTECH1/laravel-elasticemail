<?php

namespace FabioTech\LaravelElasticEmail\Transport;

use ElasticEmail\Api\EmailsApi;
use ElasticEmail\ApiException;
use ElasticEmail\Model\BodyContentType;
use ElasticEmail\Model\BodyPart;
use ElasticEmail\Model\EmailContent;
use ElasticEmail\Model\EmailSend;
use ElasticEmail\Model\EmailTransactionalMessageData;
use ElasticEmail\Model\MessageAttachment;
use ElasticEmail\Model\TransactionalRecipient;
use ElasticEmail\ObjectSerializer;
use FabioTech\LaravelElasticEmail\Exception\MessageRejectedException;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\TextPart;

final class ElasticEmailTransport extends AbstractTransport
{
    public const HARD_MAX_ATTACHMENTS = 100;

    public const HARD_MAX_HEADER_BYTES = 65_536;

    public const HARD_MAX_METADATA_BYTES = 1_000_000;

    public const HARD_MAX_PROVIDER_BYTES = 20_000_000;

    public const HARD_MAX_RECIPIENTS = 10_000;

    public const HARD_MAX_RESPONSE_BYTES = 1_000_000;

    public function __construct(
        string $key,
        private readonly EmailsApi $emailsApi,
        ?LoggerInterface $logger = null,
        private readonly int $maxBodyBytes = 2_000_000,
        private readonly int $maxAttachments = 10,
        private readonly int $maxAttachmentBytes = 10_000_000,
        private readonly int $maxMessageBytes = 15_000_000,
        private readonly int $maxPayloadBytes = 20_000_000,
        private readonly int $maxRecipients = 1_000,
        private readonly int $maxHeaderBytes = 16_384,
        private readonly int $maxMetadataBytes = 262_144,
    ) {
        if (trim($key) === '') {
            throw new InvalidArgumentException('Elastic Email API key is not configured.');
        }

        foreach ([
            'maxBodyBytes' => $this->maxBodyBytes,
            'maxAttachments' => $this->maxAttachments,
            'maxAttachmentBytes' => $this->maxAttachmentBytes,
            'maxMessageBytes' => $this->maxMessageBytes,
            'maxPayloadBytes' => $this->maxPayloadBytes,
            'maxRecipients' => $this->maxRecipients,
            'maxHeaderBytes' => $this->maxHeaderBytes,
            'maxMetadataBytes' => $this->maxMetadataBytes,
        ] as $name => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException("Elastic Email {$name} must be a positive integer.");
            }
        }

        foreach ([
            'maxBodyBytes' => $this->maxBodyBytes,
            'maxAttachmentBytes' => $this->maxAttachmentBytes,
            'maxMessageBytes' => $this->maxMessageBytes,
            'maxPayloadBytes' => $this->maxPayloadBytes,
        ] as $name => $value) {
            if ($value > self::HARD_MAX_PROVIDER_BYTES) {
                throw new InvalidArgumentException("Elastic Email {$name} exceeds the supported hard limit.");
            }
        }

        if ($this->maxAttachments > self::HARD_MAX_ATTACHMENTS) {
            throw new InvalidArgumentException('Elastic Email maxAttachments exceeds the supported hard limit.');
        }

        if ($this->maxRecipients > self::HARD_MAX_RECIPIENTS) {
            throw new InvalidArgumentException('Elastic Email maxRecipients exceeds the supported hard limit.');
        }

        if ($this->maxHeaderBytes > self::HARD_MAX_HEADER_BYTES) {
            throw new InvalidArgumentException('Elastic Email maxHeaderBytes exceeds the supported hard limit.');
        }

        if ($this->maxMetadataBytes > self::HARD_MAX_METADATA_BYTES) {
            throw new InvalidArgumentException('Elastic Email maxMetadataBytes exceeds the supported hard limit.');
        }

        if ($this->maxBodyBytes > $this->maxMessageBytes) {
            throw new InvalidArgumentException('Elastic Email body byte limit cannot exceed the message byte limit.');
        }

        if ($this->maxAttachmentBytes > $this->maxMessageBytes) {
            throw new InvalidArgumentException('Elastic Email attachment byte limit cannot exceed the message byte limit.');
        }

        if ($this->maxHeaderBytes > $this->maxMetadataBytes) {
            throw new InvalidArgumentException('Elastic Email header byte limit cannot exceed the metadata byte limit.');
        }

        if ($this->maxMetadataBytes > $this->maxPayloadBytes) {
            throw new InvalidArgumentException('Elastic Email metadata byte limit cannot exceed the payload byte limit.');
        }

        parent::__construct(logger: $logger);
    }

    protected function doSend(SentMessage $message): void
    {
        $originalMessage = $message->getOriginalMessage();

        if (! $originalMessage instanceof Email) {
            throw new MessageRejectedException('Elastic Email transport accepts Symfony Email messages only.');
        }

        $payload = $this->getPayload($originalMessage, $message);

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
                ...$this->emailContext($originalMessage, $payload),
                'exception_class' => $exception::class,
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

        return [
            'attachment_bytes' => array_sum(array_map(
                fn (MessageAttachment $attachment): int => (int) $attachment->getSize(),
                $attachments,
            )),
            'attachment_count' => count($attachments),
            'body_lengths' => array_map(
                fn (BodyPart $bodyPart): int => strlen((string) $bodyPart->getContent()),
                $body,
            ),
            'recipient_count' => count($email->getTo()) + count($email->getCc()) + count($email->getBcc()),
            'recipient_counts' => [
                'bcc' => count($email->getBcc()),
                'cc' => count($email->getCc()),
                'to' => count($email->getTo()),
            ],
            'subject_length' => strlen((string) $email->getSubject()),
        ];
    }

    private function isRetryable(int $statusCode): bool
    {
        return $statusCode === 0
            || in_array($statusCode, [408, 425, 429], true)
            || $statusCode >= 500;
    }

    private function getPayload(Email $email, SentMessage $message): EmailTransactionalMessageData
    {
        $attachmentParts = $email->getAttachments();
        $recipientCount = count($email->getTo()) + count($email->getCc()) + count($email->getBcc());
        $addressCount = $recipientCount + count($email->getFrom()) + count($email->getReplyTo());

        if (count($attachmentParts) > $this->maxAttachments) {
            throw new MessageRejectedException('Email has more attachments than the configured limit.');
        }

        if ($addressCount > $this->maxRecipients) {
            throw new MessageRejectedException('Email has more addresses than the configured limit.');
        }

        $messageBytes = 0;
        $metadataBytes = 0;
        $encodedAttachmentBytes = 0;
        $body = $this->getBody($email, $messageBytes, $metadataBytes);
        $attachments = [];

        foreach ($attachmentParts as $attachment) {
            $attachments[] = $this->getAttachment(
                $attachment,
                $messageBytes,
                $metadataBytes,
                $encodedAttachmentBytes,
            );
        }

        $replyTo = $this->getReplyTo($email, $metadataBytes);
        $envelopeFrom = $message->getEnvelope()->getSender()->getAddress();
        $this->consumeMetadataBytes(strlen($envelopeFrom), $metadataBytes, 'Envelope sender');
        $from = $this->formatAddress($email->getFrom()[0], $metadataBytes);
        $subject = (string) $email->getSubject();
        $this->consumeMetadataBytes(strlen($subject), $metadataBytes, 'Email subject');
        $contentData = [
            'envelope_from' => $envelopeFrom,
            'from' => $from,
            'subject' => $subject,
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

        $recipientData = ['to' => $this->getAddresses($email->getTo(), $metadataBytes)];
        $cc = $this->getAddresses($email->getCc(), $metadataBytes);
        $bcc = $this->getAddresses($email->getBcc(), $metadataBytes);

        if ($cc !== []) {
            $recipientData['cc'] = $cc;
        }

        if ($bcc !== []) {
            $recipientData['bcc'] = $bcc;
        }

        $payload = new EmailTransactionalMessageData([
            'content' => new EmailContent($contentData),
            'recipients' => new TransactionalRecipient($recipientData),
        ]);

        $this->assertPayloadWithinLimit($payload);

        return $payload;
    }

    /**
     * @return list<BodyPart>
     */
    private function getBody(Email $email, int &$messageBytes, int &$metadataBytes): array
    {
        $body = [];

        if ($email->getHtmlBody() !== null) {
            $charset = $email->getHtmlCharset();

            if ($charset !== null) {
                $this->consumeMetadataBytes(strlen($charset), $metadataBytes, 'Email charset');
            }

            $body[] = new BodyPart([
                'charset' => $charset,
                'content' => $this->bodyToString($email->getHtmlBody(), $messageBytes),
                'content_type' => BodyContentType::HTML,
            ]);
        }

        if ($email->getTextBody() !== null) {
            $charset = $email->getTextCharset();

            if ($charset !== null) {
                $this->consumeMetadataBytes(strlen($charset), $metadataBytes, 'Email charset');
            }

            $body[] = new BodyPart([
                'charset' => $charset,
                'content' => $this->bodyToString($email->getTextBody(), $messageBytes),
                'content_type' => BodyContentType::PLAIN_TEXT,
            ]);
        }

        return $body;
    }

    /**
     * @param  resource|string  $body
     */
    private function bodyToString($body, int &$messageBytes): string
    {
        if (is_string($body)) {
            $this->consumeMessageBytes(strlen($body), $messageBytes, $this->maxBodyBytes, 'Email body');

            return $body;
        }

        $metadata = stream_get_meta_data($body);

        if ($metadata['seekable'] !== true || ! stream_is_local($body) || rewind($body) === false) {
            throw new MessageRejectedException('Email body streams must be local and seekable.');
        }

        $remainingMessageBytes = max(0, $this->maxMessageBytes - $messageBytes);
        $readLimit = min($this->maxBodyBytes, $remainingMessageBytes) + 1;
        $contents = stream_get_contents($body, $readLimit);

        if ($contents === false) {
            throw new MessageRejectedException('Unable to read an email body stream safely.');
        }

        $this->consumeMessageBytes(strlen($contents), $messageBytes, $this->maxBodyBytes, 'Email body');

        return $contents;
    }

    private function getAttachment(
        DataPart $attachment,
        int &$messageBytes,
        int &$metadataBytes,
        int &$encodedAttachmentBytes,
    ): MessageAttachment {
        $filename = $attachment->getFilename() ?? 'attachment';
        $contentType = $attachment->getContentType();
        $this->consumeMetadataBytes(strlen($filename), $metadataBytes, 'Attachment filename');
        $this->consumeMetadataBytes(strlen($contentType), $metadataBytes, 'Attachment content type');
        $encodingHeader = $attachment->getPreparedHeaders()->get('Content-Transfer-Encoding');
        $encoding = strtolower($encodingHeader?->getBodyAsString() ?? '');

        if ($encoding !== 'base64') {
            throw new MessageRejectedException('Email attachments must use base64 transfer encoding.');
        }

        $attachmentBody = $this->attachmentBody($attachment);
        $knownBytes = $this->knownAttachmentBytes($attachmentBody);

        if ($knownBytes !== null) {
            $this->assertAttachmentBytesWithinLimit($knownBytes, $messageBytes);
            $knownEncodedBytes = 4 * intdiv($knownBytes + 2, 3);

            if ($knownEncodedBytes > $this->maxPayloadBytes - $encodedAttachmentBytes) {
                throw new MessageRejectedException('Email payload exceeds the configured encoded byte limit.');
            }
        }

        $encodedContents = '';
        $attachmentBytes = 0;
        $carry = '';

        foreach ($this->attachmentChunks($attachmentBody) as $chunk) {
            $chunkBytes = strlen($chunk);
            $this->assertAttachmentBytesWithinLimit($attachmentBytes + $chunkBytes, $messageBytes);
            $attachmentBytes += $chunkBytes;
            $carry .= $chunk;
            $bytesToEncode = strlen($carry) - (strlen($carry) % 3);

            if ($bytesToEncode === 0) {
                continue;
            }

            $this->appendEncodedAttachment(
                base64_encode(substr($carry, 0, $bytesToEncode)),
                $encodedContents,
                $encodedAttachmentBytes,
            );
            $carry = substr($carry, $bytesToEncode);
        }

        if ($carry !== '') {
            $this->appendEncodedAttachment(
                base64_encode($carry),
                $encodedContents,
                $encodedAttachmentBytes,
            );
        }

        $this->consumeMessageBytes(
            bytes: $attachmentBytes,
            messageBytes: $messageBytes,
            partLimit: $this->maxAttachmentBytes,
            label: 'Email attachment',
        );

        return new MessageAttachment([
            'binary_content' => $encodedContents,
            'content_type' => $contentType,
            'name' => $filename,
            'size' => $attachmentBytes,
        ]);
    }

    /**
     * @return resource|string|File
     */
    private function attachmentBody(DataPart $attachment)
    {
        try {
            static $bodyProperty = null;
            $bodyProperty ??= new ReflectionProperty(TextPart::class, 'body');
            $body = $bodyProperty->getValue($attachment);
        } catch (\ReflectionException) {
            throw new MessageRejectedException('Unable to inspect the email attachment safely.');
        }

        if (! is_string($body) && ! is_resource($body) && ! $body instanceof File) {
            throw new MessageRejectedException('Unsupported email attachment body.');
        }

        return $body;
    }

    /**
     * @param  resource|string|File  $body
     */
    private function knownAttachmentBytes($body): ?int
    {
        if (is_string($body)) {
            return strlen($body);
        }

        if ($body instanceof File) {
            $bytes = filesize($body->getPath());

            return $bytes === false ? null : $bytes;
        }

        $metadata = stream_get_meta_data($body);

        if ($metadata['seekable'] !== true || ! stream_is_local($body)) {
            throw new MessageRejectedException('Email attachment streams must be local and seekable.');
        }

        $statistics = fstat($body);

        return $statistics === false ? null : $statistics['size'];
    }

    /**
     * @param  resource|string|File  $body
     * @return iterable<string>
     */
    private function attachmentChunks($body): iterable
    {
        $handle = null;
        $closeHandle = false;

        if (is_string($body)) {
            for ($offset = 0, $length = strlen($body); $offset < $length; $offset += 24_576) {
                yield substr($body, $offset, 24_576);
            }

            return;
        }

        if ($body instanceof File) {
            $handle = @fopen($body->getPath(), 'rb');
            $closeHandle = true;
        } else {
            $handle = $body;
            rewind($handle);
        }

        if ($handle === false) {
            throw new MessageRejectedException('Unable to open the email attachment safely.');
        }

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 24_576);

                if ($chunk === false) {
                    throw new MessageRejectedException('Unable to read the email attachment safely.');
                }

                if ($chunk === '') {
                    break;
                }

                yield $chunk;
            }
        } finally {
            if ($closeHandle) {
                fclose($handle);
            }
        }
    }

    private function assertAttachmentBytesWithinLimit(int $attachmentBytes, int $messageBytes): void
    {
        if ($attachmentBytes > $this->maxAttachmentBytes) {
            throw new MessageRejectedException('Email attachment exceeds the configured byte limit.');
        }

        if ($attachmentBytes > $this->maxMessageBytes - $messageBytes) {
            throw new MessageRejectedException('Email content exceeds the configured aggregate byte limit.');
        }
    }

    private function appendEncodedAttachment(
        string $chunk,
        string &$encodedContents,
        int &$encodedAttachmentBytes,
    ): void {
        $chunkBytes = strlen($chunk);

        if ($chunkBytes > $this->maxPayloadBytes - $encodedAttachmentBytes) {
            throw new MessageRejectedException('Email payload exceeds the configured encoded byte limit.');
        }

        $encodedContents .= $chunk;
        $encodedAttachmentBytes += $chunkBytes;
    }

    private function consumeMessageBytes(int $bytes, int &$messageBytes, int $partLimit, string $label): void
    {
        if ($bytes > $partLimit) {
            throw new MessageRejectedException("{$label} exceeds the configured byte limit.");
        }

        if ($bytes > $this->maxMessageBytes - $messageBytes) {
            throw new MessageRejectedException('Email content exceeds the configured aggregate byte limit.');
        }

        $messageBytes += $bytes;
    }

    private function assertPayloadWithinLimit(EmailTransactionalMessageData $payload): void
    {
        $encodedBytes = 0;
        $this->measureJsonValue(ObjectSerializer::sanitizeForSerialization($payload), $encodedBytes);
    }

    private function measureJsonValue(mixed $value, int &$encodedBytes): void
    {
        if (is_string($value)) {
            $this->measureJsonString($value, $encodedBytes);

            return;
        }

        if (is_array($value)) {
            $this->consumePayloadBytes(2, $encodedBytes);
            $isList = array_is_list($value);
            $first = true;

            foreach ($value as $key => $item) {
                if (! $first) {
                    $this->consumePayloadBytes(1, $encodedBytes);
                }

                if (! $isList) {
                    $this->measureJsonString((string) $key, $encodedBytes);
                    $this->consumePayloadBytes(1, $encodedBytes);
                }

                $this->measureJsonValue($item, $encodedBytes);
                $first = false;
            }

            return;
        }

        if (is_object($value)) {
            $this->consumePayloadBytes(2, $encodedBytes);
            $first = true;

            foreach (get_object_vars($value) as $key => $item) {
                if (! $first) {
                    $this->consumePayloadBytes(1, $encodedBytes);
                }

                $this->measureJsonString($key, $encodedBytes);
                $this->consumePayloadBytes(1, $encodedBytes);
                $this->measureJsonValue($item, $encodedBytes);
                $first = false;
            }

            return;
        }

        if (is_scalar($value) || $value === null) {
            try {
                $encodedScalar = json_encode($value, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new MessageRejectedException('Unable to encode the email payload safely.');
            }

            $this->consumePayloadBytes(strlen($encodedScalar), $encodedBytes);

            return;
        }

        throw new MessageRejectedException('Unable to encode the email payload safely.');
    }

    private function measureJsonString(string $value, int &$encodedBytes): void
    {
        $this->consumePayloadBytes(2, $encodedBytes);
        $length = strlen($value);

        for ($offset = 0; $offset < $length; $offset++) {
            $byte = ord($value[$offset]);

            if ($byte <= 0x7F) {
                $escapedBytes = match (true) {
                    $byte === 0x22, $byte === 0x2F, $byte === 0x5C => 2,
                    in_array($byte, [0x08, 0x09, 0x0A, 0x0C, 0x0D], true) => 2,
                    $byte <= 0x1F => 6,
                    default => 1,
                };
                $this->consumePayloadBytes($escapedBytes, $encodedBytes);

                continue;
            }

            $sequenceBytes = $this->validUtf8SequenceBytes($value, $offset, $length);
            $this->consumePayloadBytes($sequenceBytes === 4 ? 12 : 6, $encodedBytes);
            $offset += $sequenceBytes - 1;
        }
    }

    private function validUtf8SequenceBytes(string $value, int $offset, int $length): int
    {
        $first = ord($value[$offset]);
        $second = $offset + 1 < $length ? ord($value[$offset + 1]) : -1;
        $third = $offset + 2 < $length ? ord($value[$offset + 2]) : -1;
        $fourth = $offset + 3 < $length ? ord($value[$offset + 3]) : -1;

        if ($first >= 0xC2 && $first <= 0xDF && $this->isUtf8Continuation($second)) {
            return 2;
        }

        if ($first >= 0xE0 && $first <= 0xEF
            && $this->isUtf8Continuation($third)
            && (($first === 0xE0 && $second >= 0xA0 && $second <= 0xBF)
                || ($first === 0xED && $second >= 0x80 && $second <= 0x9F)
                || (in_array($first, [0xE1, 0xE2, 0xE3, 0xE4, 0xE5, 0xE6, 0xE7, 0xE8, 0xE9, 0xEA, 0xEB, 0xEC, 0xEE, 0xEF], true)
                    && $this->isUtf8Continuation($second)))) {
            return 3;
        }

        if ($first >= 0xF0 && $first <= 0xF4
            && $this->isUtf8Continuation($third)
            && $this->isUtf8Continuation($fourth)
            && (($first === 0xF0 && $second >= 0x90 && $second <= 0xBF)
                || ($first === 0xF4 && $second >= 0x80 && $second <= 0x8F)
                || (in_array($first, [0xF1, 0xF2, 0xF3], true) && $this->isUtf8Continuation($second)))) {
            return 4;
        }

        throw new MessageRejectedException('Unable to encode the email payload safely.');
    }

    private function isUtf8Continuation(int $byte): bool
    {
        return $byte >= 0x80 && $byte <= 0xBF;
    }

    private function consumePayloadBytes(int $bytes, int &$encodedBytes): void
    {
        if ($bytes > $this->maxPayloadBytes - $encodedBytes) {
            throw new MessageRejectedException('Email payload exceeds the configured encoded byte limit.');
        }

        $encodedBytes += $bytes;
    }

    private function consumeMetadataBytes(int $bytes, int &$metadataBytes, string $label): void
    {
        if ($bytes > $this->maxHeaderBytes) {
            throw new MessageRejectedException("{$label} exceeds the configured header byte limit.");
        }

        if ($bytes > $this->maxMetadataBytes - $metadataBytes) {
            throw new MessageRejectedException('Email metadata exceeds the configured aggregate byte limit.');
        }

        $metadataBytes += $bytes;
    }

    private function getReplyTo(Email $email, int &$metadataBytes): ?string
    {
        $replyTo = $this->getAddresses($email->getReplyTo(), $metadataBytes);

        return $replyTo === [] ? null : implode(', ', $replyTo);
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<string>
     */
    private function getAddresses(array $addresses, int &$metadataBytes): array
    {
        $formatted = [];

        foreach ($addresses as $address) {
            $formatted[] = $this->formatAddress($address, $metadataBytes);
        }

        return $formatted;
    }

    private function formatAddress(Address $address, int &$metadataBytes): string
    {
        if (strlen($address->getAddress()) + strlen($address->getName()) > $this->maxHeaderBytes) {
            throw new MessageRejectedException('Email address exceeds the configured header byte limit.');
        }

        $formatted = $address->toString();
        $this->consumeMetadataBytes(strlen($formatted), $metadataBytes, 'Email address');

        return $formatted;
    }

    public function __toString(): string
    {
        return 'elasticemail';
    }
}
