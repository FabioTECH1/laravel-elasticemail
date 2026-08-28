<?php

namespace FabioTech\LaravelElasticEmail\Transport;

use FabioTech\LaravelElasticEmail\Exception\ClassifiedRequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

final readonly class ClassifyRequestFailure
{
    private const DNS_ERROR_NUMBERS = [5, 6];

    private const TLS_ERROR_NUMBERS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98];

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            try {
                $promise = $handler($request, $options);
            } catch (Throwable $exception) {
                throw $this->classify($request, $exception);
            }

            return $promise->then(
                null,
                function (mixed $reason) use ($request): PromiseInterface {
                    if (! $reason instanceof Throwable) {
                        return Create::rejectionFor($reason);
                    }

                    throw $this->classify($request, $reason);
                },
            );
        };
    }

    private function classify(RequestInterface $request, Throwable $exception): Throwable
    {
        if ($exception instanceof ClassifiedRequestException) {
            return $exception;
        }

        if ($exception instanceof ConnectException) {
            $handlerContext = $exception->getHandlerContext();
            $failureKind = $this->connectionFailureKind($handlerContext['errno'] ?? null);

            return new ClassifiedRequestException(
                failureKind: $failureKind,
                safeMessage: $this->safeMessage($failureKind),
                request: $request,
                previous: $exception,
                handlerContext: $handlerContext,
            );
        }

        if ($exception instanceof RequestException) {
            return new ClassifiedRequestException(
                failureKind: ClassifiedRequestException::REQUEST,
                safeMessage: $this->safeMessage(ClassifiedRequestException::REQUEST),
                request: $request,
                response: $exception->getResponse(),
                previous: $exception,
                handlerContext: $exception->getHandlerContext(),
            );
        }

        return $exception;
    }

    private function connectionFailureKind(mixed $errorNumber): string
    {
        if (! is_int($errorNumber) && ! (is_string($errorNumber) && ctype_digit($errorNumber))) {
            return ClassifiedRequestException::CONNECT;
        }

        $errorNumber = (int) $errorNumber;

        if ($errorNumber === 28) {
            return ClassifiedRequestException::TIMEOUT;
        }

        if (in_array($errorNumber, self::DNS_ERROR_NUMBERS, true)) {
            return ClassifiedRequestException::DNS;
        }

        if (in_array($errorNumber, self::TLS_ERROR_NUMBERS, true)) {
            return ClassifiedRequestException::TLS;
        }

        return ClassifiedRequestException::CONNECT;
    }

    private function safeMessage(string $failureKind): string
    {
        return match ($failureKind) {
            ClassifiedRequestException::DNS => 'Elastic Email API provider host could not be resolved.',
            ClassifiedRequestException::TIMEOUT => 'Elastic Email API request timed out.',
            ClassifiedRequestException::TLS => 'Elastic Email API TLS connection failed.',
            ClassifiedRequestException::REQUEST => 'Elastic Email API request failed.',
            default => 'Elastic Email API connection failed.',
        };
    }
}
