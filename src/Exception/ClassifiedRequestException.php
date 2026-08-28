<?php

namespace FabioTech\LaravelElasticEmail\Exception;

use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class ClassifiedRequestException extends RequestException
{
    public const CONNECT = 'connect';

    public const DNS = 'dns';

    public const REQUEST = 'request';

    public const RESPONSE_LIMIT = 'response_limit';

    public const RESPONSE_READ = 'response_read';

    public const TIMEOUT = 'timeout';

    public const TLS = 'tls';

    private const FAILURE_KINDS = [
        self::CONNECT,
        self::DNS,
        self::REQUEST,
        self::RESPONSE_LIMIT,
        self::RESPONSE_READ,
        self::TIMEOUT,
        self::TLS,
    ];

    /**
     * @param  array<string, mixed>  $handlerContext
     */
    public function __construct(
        private readonly string $failureKind,
        string $safeMessage,
        RequestInterface $request,
        ?ResponseInterface $response = null,
        ?Throwable $previous = null,
        array $handlerContext = [],
    ) {
        if (! in_array($this->failureKind, self::FAILURE_KINDS, true)) {
            throw new InvalidArgumentException('Unsupported Elastic Email request failure kind.');
        }

        parent::__construct(
            "{$safeMessage} [elasticemail-failure:{$this->failureKind}]",
            $request,
            $response,
            $previous,
            $handlerContext,
        );
    }

    public function failureKind(): string
    {
        return $this->failureKind;
    }

    public static function failureKindFromMessage(string $message): ?string
    {
        if (preg_match('/\[elasticemail-failure:([a-z_]+)\]/', $message, $matches) !== 1) {
            return null;
        }

        $failureKind = $matches[1];

        return in_array($failureKind, self::FAILURE_KINDS, true) ? $failureKind : null;
    }
}
