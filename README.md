# Laravel Elastic Email

A Laravel Mail transport for the Elastic Email transactional HTTP API, powered by the official `elasticemail/elasticemail-php` SDK.

This package does not use SMTP.

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- An Elastic Email API key with `SendHttp` permission

## Installation

```bash
composer require dataphyte/laravel-elasticemail
```

Laravel discovers the package service provider automatically. Configure the mailer and API key:

```env
MAIL_MAILER=elasticemail
ELASTIC_EMAIL_API_KEY=your-api-key
```

The package automatically adds the `elasticemail` mailer. No application service-provider code is required.

## Configuration

The defaults are suitable for most applications:

```env
ELASTIC_EMAIL_CONNECT_TIMEOUT=5
ELASTIC_EMAIL_TIMEOUT=15
```

Publish the configuration only when you need to customize it:

```bash
php artisan vendor:publish --tag=elasticemail-config
```

Application-level values in `config/mail.php` override package defaults:

```php
'elasticemail' => [
    'transport' => 'elasticemail',
    'key' => env('ELASTIC_EMAIL_API_KEY'),
    'connect_timeout' => 5,
    'timeout' => 15,
],
```

## Usage

Use Laravel Mailables and Notifications normally:

```php
Mail::to('primary@example.com')
    ->cc('copy@example.com')
    ->bcc('archive@example.com')
    ->send(new InvoiceMail($invoice));
```

The transport supports:

- HTML and plain-text bodies
- To, CC, and BCC recipients
- Multiple Reply-To addresses
- File, storage, and in-memory attachments
- Envelope sender addresses
- Elastic Email message and transaction identifiers
- Laravel queued Mailables and Notifications

## Failures and retries

Provider and network failures are converted to Symfony `TransportException` instances so Laravel queues can handle them normally. Logs include structured delivery metadata and redact API keys, email addresses, URLs, phone numbers, and token-like values.

The transport classifies connection failures, HTTP 408, 425, 429, and 5xx responses as retryable in its log context. It does not retry automatically because an interrupted response may occur after the provider accepted the email, and an immediate retry could produce a duplicate message. Configure retry and backoff behavior on your Laravel queues according to your application's duplicate-delivery tolerance.

## Testing

```bash
composer test
composer format
```

## License

Laravel Elastic Email is open-source software licensed under the MIT license.
