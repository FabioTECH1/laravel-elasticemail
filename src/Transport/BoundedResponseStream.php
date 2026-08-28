<?php

namespace FabioTech\LaravelElasticEmail\Transport;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class BoundedResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $writtenBytes = 0;

    public function __construct(
        private readonly int $maxBytes,
        private readonly RequestInterface $request,
    ) {
        if ($this->maxBytes <= 0) {
            throw new RuntimeException('Elastic Email API response byte limit must be positive.');
        }

        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'w+'));
    }

    public function write($string): int
    {
        $chunkBytes = strlen($string);

        if ($chunkBytes > $this->maxBytes - $this->writtenBytes) {
            throw new RequestException(
                'Elastic Email API response exceeded the configured byte limit.',
                $this->request,
            );
        }

        $writtenBytes = $this->stream->write($string);
        $this->writtenBytes += $writtenBytes;

        return $writtenBytes;
    }
}
