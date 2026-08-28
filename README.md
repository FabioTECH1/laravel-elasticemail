# Laravel Elastic Email

A Laravel Mail transport for the Elastic Email transactional HTTP API, powered by the official `elasticemail/elasticemail-php` SDK. It does not use SMTP.

## Requirements

- PHP 8.3 or newer
- PHP cURL extension (required for enforceable connection and wall-clock request timeouts)
- Laravel 12.61.1+ or 13.12.0+
- An Elastic Email API key restricted to the `SendHttp` permission

## Installation

```bash
composer require fabiotech/laravel-elasticemail
```

Laravel discovers the package automatically. Configure the mailer and API key:

```env
MAIL_MAILER=elasticemail
ELASTIC_EMAIL_API_KEY=your-api-key
```

No application service-provider code and no manual `config/mail.php` entry are required.

## Usage

Use Laravel Mailables and Notifications normally:

```php
use Illuminate\Support\Facades\Mail;

Mail::to('primary@example.com')
    ->cc('copy@example.com')
    ->bcc('archive@example.com')
    ->send(new InvoiceMail($invoice));
```

The transport supports HTML and plain-text bodies, To/CC/BCC, multiple Reply-To addresses, envelope senders, inline and regular attachments, queued mail, and provider message identifiers. Laravel-generated `Email` and `TemplatedEmail` messages are supported; arbitrary raw MIME messages are rejected before conversion so they cannot bypass resource limits.

## Production configuration

The secure defaults are:

| Environment variable | Default | Hard ceiling | Purpose |
| --- | ---: | ---: | --- |
| `ELASTIC_EMAIL_CONNECT_TIMEOUT` | `5` | `60` | TCP/TLS connection timeout in seconds |
| `ELASTIC_EMAIL_TIMEOUT` | `15` | `600` | Complete HTTP request timeout in seconds |
| `ELASTIC_EMAIL_MAX_BODY_BYTES` | `2000000` | `20000000` | Maximum bytes in each HTML or text body |
| `ELASTIC_EMAIL_MAX_ATTACHMENTS` | `10` | `100` | Maximum attachment count |
| `ELASTIC_EMAIL_MAX_ATTACHMENT_BYTES` | `10000000` | `20000000` | Maximum raw bytes in one attachment |
| `ELASTIC_EMAIL_MAX_MESSAGE_BYTES` | `15000000` | `20000000` | Maximum aggregate raw body and attachment bytes |
| `ELASTIC_EMAIL_MAX_PAYLOAD_BYTES` | `20000000` | `20000000` | Maximum encoded JSON request bytes |
| `ELASTIC_EMAIL_MAX_RECIPIENTS` | `1000` | `10000` | Maximum combined From/Reply-To/To/CC/BCC addresses |
| `ELASTIC_EMAIL_MAX_HEADER_BYTES` | `16384` | `65536` | Maximum bytes in one subject/address/filename metadata value |
| `ELASTIC_EMAIL_MAX_METADATA_BYTES` | `262144` | `1000000` | Maximum aggregate address/header/filename metadata bytes |
| `ELASTIC_EMAIL_MAX_RESPONSE_BYTES` | `65536` | `1000000` | Maximum decoded provider response bytes |

Size/count limits must be plain positive decimal integers and cannot exceed the hard ceilings. Timeouts may be positive decimals within their ceilings. The payload ceiling follows Elastic Email's documented 20 MB message limit; the other ceilings keep all size arithmetic bounded. Set lower application-specific limits when users can influence outgoing content. The package limits transport-side amplification, but it cannot protect memory already consumed while an application uploads, builds, or serializes a queued message.

Publish the configuration only when you need a checked-in override:

```bash
php artisan vendor:publish --tag=elasticemail-config
php artisan config:cache
```

Values in `config/mail.php` override package defaults. Keep the same finite limits when overriding the mailer:

```php
'elasticemail' => [
    'transport' => 'elasticemail',
    'key' => env('ELASTIC_EMAIL_API_KEY'),
    'connect_timeout' => 5,
    'timeout' => 15,
    'max_body_bytes' => 2_000_000,
    'max_attachments' => 10,
    'max_attachment_bytes' => 10_000_000,
    'max_message_bytes' => 15_000_000,
    'max_payload_bytes' => 20_000_000,
    'max_recipients' => 1_000,
    'max_header_bytes' => 16_384,
    'max_metadata_bytes' => 262_144,
    'max_response_bytes' => 65_536,
],
```

Body resources must be local and seekable. Symfony's normal attachment APIs use base64 transfer encoding and are supported for in-memory, resource-backed, storage, file, and inline attachments. Custom non-base64 attachment encodings are rejected.

## Security and privacy

- Give the API key only `SendHttp` permission, keep it in an environment secret store, and rotate it immediately if it may have leaked.
- TLS verification is enabled, redirects are disabled, and both request time and decoded response size are bounded on the package-managed client.
- Failure logs contain only counts, byte lengths, elapsed milliseconds, a safe failure category, the numeric status, retryability, and exception class. Addresses, domains, subjects, content, provider error prose, request IDs, and credentials are never logged by this package.
- Validate authorization, destination addresses, uploads, and rate limits before constructing a Mailable. Isolate mail queues from latency-sensitive jobs and set a finite PHP memory limit.
- The response cap applies when Laravel creates the transport through this package. If you directly instantiate `ElasticEmailTransport` with a custom `EmailsApi`, that client is your security boundary and must enforce an equivalent decoded-response limit, TLS verification, redirect policy, and timeouts.

See [SECURITY.md](SECURITY.md) for private vulnerability reporting and supported versions.

## Failures and retries

Provider and network failures become safe Symfony `TransportException` instances. The exception message distinguishes HTTP, timeout, DNS, TLS, connection, provider-response, response-read, and response-limit failures without exposing provider-controlled data. Local policy failures use `MessageRejectedException`, a permanent transport failure that applications can catch separately.

Failure logs include `failure_kind` (`api`, `connect`, `dns`, `http`, `provider_response`, `request`, `response_limit`, `response_read`, `timeout`, or `tls`) and non-negative `elapsed_ms` fields. Connection failures, HTTP 408, 425, 429, and 5xx responses are marked retryable; a response-limit failure is not. The transport does not retry automatically: a timeout can happen after the provider accepted the email, so blind retries can create duplicate deliveries. Configure finite queue attempts, exponential backoff, and an application idempotency strategy appropriate to the message. Do not endlessly retry `MessageRejectedException` jobs.

## Deployment checklist

1. Use a least-privilege `SendHttp` key and confirm it is absent from code, logs, images, and shell history.
2. Set application upload/content limits at or below the package limits.
3. Cache production configuration, restart long-lived workers, and send a canary email.
4. Configure finite queue attempts/backoff, worker memory limits, monitoring, and failed-job alerts.
5. Run `composer audit --locked`, the test suite, and your application's mail tests before deployment.
6. Monitor delivery failures by status/count, without adding recipient or provider-response data to logs.

## Development

```bash
composer install
composer validate --strict
composer audit --locked
composer analyse
composer test
composer format
```

The CI matrix covers supported Laravel, PHP, and Symfony combinations, including the minimum direct production dependencies. CodeQL, dependency review, Dependabot, and exact GitHub Action commit pins protect the release workflow.

## License

Laravel Elastic Email is open-source software licensed under the MIT license.
