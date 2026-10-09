<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client that answers from a queue and keeps the requests.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var list<ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface> */
    private array $queue = [];

    /**
     * @param array<string, string> $headers
     */
    public function json(mixed $body, int $status = 200, array $headers = []): self
    {
        $this->queue[] = new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
        return $this;
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $data
     * @param array<string, mixed>|null $meta
     */
    public function success(mixed $data, ?array $meta = null, int $status = 200): self
    {
        return $this->json(['status' => 'success', 'success' => true, 'data' => $data] + (null !== $meta ? ['meta' => $meta] : []), $status);
    }

    /**
     * @param array<string, mixed>|null $errorData
     * @param array<string, string> $headers
     */
    public function failure(int $status, string $code, string $message = 'Fehler', ?array $errorData = null, array $headers = []): self
    {
        return $this->json(['status' => 'failed', 'success' => false, 'error' => $message, 'error_message' => $message, 'error_code' => $code] + (null !== $errorData ? ['error_data' => $errorData] : []), $status, $headers);
    }

    public function token(string $token = 'token-1', int $expiresIn = 3600): self
    {
        return $this->json(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn, 'scope' => 'books']);
    }

    public function push(ResponseInterface|\Throwable $response): self
    {
        $this->queue[] = $response;
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new \LogicException('No answer queued for '.$request->getMethod().' '.$request->getUri());
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next instanceof ResponseInterface ? $next : $next($request);
    }

    public function last(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)] ?? throw new \LogicException('No request sent.');
    }

    /**
     * Query parameters of a request, decoded like PHP does on the server.
     *
     * @return array<string, mixed>
     */
    public static function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);
        /** @var array<string, mixed> $query */
        return $query;
    }
}
