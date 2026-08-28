<?php

namespace FabioTech\LaravelElasticEmail\Transport;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class LimitResponseBody
{
    public function __construct(private int $maxBytes)
    {
        if ($this->maxBytes <= 0 || $this->maxBytes > ElasticEmailTransport::HARD_MAX_RESPONSE_BYTES) {
            throw new \InvalidArgumentException('Elastic Email API response byte limit is outside the supported range.');
        }
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $options['sink'] = new BoundedResponseStream($this->maxBytes, $request);

            return $handler($request, $options)->then(
                fn (ResponseInterface $response): ResponseInterface => $this->limit($request, $response),
            );
        };
    }

    private function limit(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $declaredLength = $response->getHeaderLine('Content-Length');

        if (ctype_digit($declaredLength) && (int) $declaredLength > $this->maxBytes) {
            throw new RequestException('Elastic Email API response exceeded the configured byte limit.', $request);
        }

        $body = $response->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $contents = '';

            while (! $body->eof() && strlen($contents) <= $this->maxBytes) {
                $remaining = $this->maxBytes - strlen($contents) + 1;
                $chunk = $body->read(min(8_192, $remaining));

                if ($chunk === '') {
                    break;
                }

                $contents .= $chunk;
            }
        } catch (Throwable) {
            throw new RequestException('Unable to read the Elastic Email API response safely.', $request);
        }

        if (strlen($contents) > $this->maxBytes || ! $body->eof()) {
            throw new RequestException('Elastic Email API response exceeded the configured byte limit.', $request);
        }

        return $response->withBody(Utils::streamFor($contents));
    }
}
