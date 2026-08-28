# Security Policy

## Supported versions

| Version | Security support |
| --- | --- |
| `0.3.x` | Supported |
| `0.2.x` and older | Upgrade required |

Pre-1.0 releases may contain backward-compatible security restrictions. Security fixes are released on the latest minor line.

## Reporting a vulnerability

Report vulnerabilities privately through [GitHub Security Advisories](https://github.com/FabioTECH1/laravel-elasticemail/security/advisories/new). Do not open a public issue before a coordinated fix is available.

Include the affected version, realistic attack preconditions, reproduction steps or a minimal proof of concept, impact, and any suggested remediation. Do not include live API keys, recipient data, production email content, or credentials. Use synthetic data and revoke any credential accidentally shared.

We aim to acknowledge a complete report within three business days and provide a status update within seven business days. Fix and disclosure timing depends on severity, exploitability, and coordination needs. Please allow a reasonable remediation window before public disclosure.

## Security boundaries

The package owns transport payload limits, safe failure logging, its package-created HTTP client's TLS/redirect/timeout/response-size policy, and Laravel mailer registration. Consuming applications remain responsible for authorization, upload and request limits, recipient policy, queue isolation/retry behavior, secret storage, log access, PHP memory limits, and any custom injected `EmailsApi` client.
