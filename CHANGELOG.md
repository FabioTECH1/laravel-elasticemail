# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

## 0.3.2 - 2026-09-03

- Implement a package-owned compatibility API client to prevent `\GuzzleHttp\Utils::jsonEncode()` deprecation warnings on Guzzle 7.15+ when constructing transactional email payloads.

## 0.3.1 - 2026-08-28

- Add privacy-safe failure categories and elapsed milliseconds to API failure logs.
- Preserve timeout, DNS, TLS, connection, response-read, and response-limit diagnoses through the Elastic Email SDK's exception wrapper.
- Expose specific safe transport exception messages without logging provider-controlled response data.

## 0.3.0 - 2026-08-28

- Bound body, attachment, aggregate message, metadata/header, recipient, encoded payload, and provider response sizes.
- Reject unsupported raw MIME messages, unsafe body streams, and non-base64 attachment encodings before the provider call.
- Remove address domains and all provider-controlled strings from failure logs.
- Disable redirects and explicitly enforce TLS verification on the package-managed HTTP client.
- Add a permanent `MessageRejectedException` for local policy failures.
- Add production guidance, a supported-version security policy, dependency auditing, lowest-dependency tests, CodeQL, dependency review, Dependabot, and immutable CI action pins.
- Raise direct dependency minimums above all advisories known at release time.

## 0.2.0 - 2026-08-28

- Add the Laravel 12 and 13 package implementation.
- Add the Elastic Email transactional API transport using the official PHP SDK.
- Add support for CC, BCC, Reply-To, multipart bodies, and attachments.
- Add configurable timeouts and redacted failure diagnostics.
