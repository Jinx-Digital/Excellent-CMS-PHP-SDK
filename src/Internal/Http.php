<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Internal;

use ExcellentCms\Sdk\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 client and PSR-17 factories, shared by the token endpoint and the API.
 *
 * @internal
 */
final class Http
{
    public function __construct(
        public readonly ClientInterface $client,
        public readonly RequestFactoryInterface $requests,
        public readonly StreamFactoryInterface $streams,
        private readonly string $userAgent,
    ) {
    }

    public function request(string $method, string $url): RequestInterface
    {
        return $this->requests->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->userAgent);
    }

    public function send(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(sprintf('%s %s failed: %s', $request->getMethod(), (string)$request->getUri(), $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return array<mixed>
     */
    public static function json(ResponseInterface $response): array
    {
        $body = (string)$response->getBody();
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException(sprintf('The answer (HTTP %d) is no JSON: %s', $response->getStatusCode(), mb_strimwidth($body, 0, 200, '…')), 0, $e);
        }
        if (!is_array($data)) {
            throw new TransportException(sprintf('The answer (HTTP %d) is no JSON object.', $response->getStatusCode()));
        }
        return $data;
    }
}
