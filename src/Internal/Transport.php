<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Internal;

use ExcellentCms\Sdk\Auth\Authentication;
use ExcellentCms\Sdk\Exception\AccessDeniedException;
use ExcellentCms\Sdk\Exception\ApiException;
use ExcellentCms\Sdk\Exception\AuthenticationException;
use ExcellentCms\Sdk\Exception\InvalidArgumentException;
use ExcellentCms\Sdk\Exception\NotFoundException;
use ExcellentCms\Sdk\Exception\RateLimitException;
use ExcellentCms\Sdk\Exception\ValidationException;
use ExcellentCms\Sdk\RateLimit;
use Psr\Http\Message\ResponseInterface;

/**
 * Requests to the API of one project: token, JSON, the {"status": …, "data": …} envelope and
 * errors as exceptions.
 *
 * @internal
 */
final class Transport
{
    private ?RateLimit $rateLimit = null;

    /** Preview token (Client::preview()): drafts and working copies instead of the live state */
    private ?string $previewToken = null;

    /**
     * @param string $projectUrl e.g. https://cms.example.com/api/v1/bibliothek
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $projectUrl,
        private readonly ?Authentication $auth,
        private readonly TokenEndpoint $tokens,
    ) {
    }

    /**
     * @param list<string> $path segments, encoded here: ['content', 'books', $id]
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body sent as JSON
     * @return array{data: mixed, meta: array<string, mixed>|null}
     */
    public function call(string $method, array $path, array $query = [], ?array $body = null): array
    {
        $payload = null;
        if (null !== $body) {
            $json = [] === $body ? '{}' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $payload = ['application/json', $json];
        }
        return $this->callWith($method, $path, $query, $payload);
    }

    /**
     * multipart/form-data: fields and one file.
     *
     * @param list<string> $path
     * @param array<string, string> $fields
     * @param resource $file
     * @return array{data: mixed, meta: array<string, mixed>|null}
     */
    public function upload(array $path, array $fields, mixed $file, string $filename): array
    {
        $boundary = 'excellent'.bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"".self::quote($name)."\"\r\n\r\n{$value}\r\n";
        }
        $content = stream_get_contents($file);
        if (false === $content) {
            throw new InvalidArgumentException('The file cannot be read.');
        }
        // The CMS detects the type from the content
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"".self::quote($filename)."\"\r\n"
            ."Content-Type: application/octet-stream\r\n\r\n{$content}\r\n--{$boundary}--\r\n";
        return $this->callWith('POST', $path, [], ['multipart/form-data; boundary='.$boundary, $body]);
    }

    /**
     * @param list<string> $path
     * @param array<string, mixed> $query
     * @param array{0: string, 1: string}|null $payload content type and body
     * @return array{data: mixed, meta: array<string, mixed>|null}
     */
    private function callWith(string $method, array $path, array $query, ?array $payload): array
    {
        $response = $this->send($method, $path, $query, $payload);
        // Expired or revoked token: fetch a new one once
        if (401 === $response->getStatusCode() && null !== $this->auth && $this->auth->invalidate()) {
            $response = $this->send($method, $path, $query, $payload);
        }
        $this->rateLimit = self::rateLimit($response) ?? $this->rateLimit;

        $data = Http::json($response);
        if ($response->getStatusCode() >= 400 || false === ($data['success'] ?? true)) {
            throw self::error($response, $data);
        }
        $meta = $data['meta'] ?? null;
        return ['data' => $data['data'] ?? null, 'meta' => is_array($meta) ? $meta : null];
    }

    /**
     * GET of a file by its address (MediaFile::$url) - without the token: files of public entities
     * are open, protected ones carry a signature in their address, and the token never goes to
     * another host (a bucket, a CDN).
     */
    public function download(string $url): ResponseInterface
    {
        $response = $this->http->send($this->http->request('GET', $url)->withHeader('Accept', '*/*'));
        $status = $response->getStatusCode();
        if ($status < 400) {
            return $response;
        }
        throw match ($status) {
            403 => new AccessDeniedException('The file is protected: use the url of a current API response (signed addresses expire).', 403, 'forbidden'),
            404 => new NotFoundException(sprintf('There is no file at %s.', $url), 404, 'not_found'),
            default => new ApiException(sprintf('Downloading %s failed with HTTP %d.', $url, $status), $status),
        };
    }

    public function withPreview(?string $token): self
    {
        $clone = clone $this;
        $clone->previewToken = null !== $token && '' !== trim($token) ? trim($token) : null;
        return $clone;
    }

    public function previewToken(): ?string
    {
        return $this->previewToken;
    }

    public function lastRateLimit(): ?RateLimit
    {
        return $this->rateLimit;
    }

    /**
     * @param list<string> $path
     * @param array<string, mixed> $query
     * @param array{0: string, 1: string}|null $payload
     */
    private function send(string $method, array $path, array $query, ?array $payload): ResponseInterface
    {
        $url = $this->projectUrl.'/'.implode('/', array_map(rawurlencode(...), $path));
        if ([] !== $query) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $request = $this->http->request($method, $url);
        $token = $this->auth?->token($this->tokens);
        if (null !== $token) {
            $request = $request->withHeader('Authorization', 'Bearer '.$token);
        }
        if (null !== $this->previewToken) {
            $request = $request->withHeader('X-Preview-Token', $this->previewToken);
        }
        if (null !== $payload) {
            $request = $request
                ->withHeader('Content-Type', $payload[0])
                ->withBody($this->http->streams->createStream($payload[1]));
        }
        return $this->http->send($request);
    }

    /**
     * Name or file name in a Content-Disposition header - like browsers do (HTML spec): " as %22,
     * no line breaks.
     */
    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }

    /**
     * @param array<mixed> $data
     */
    private static function error(ResponseInterface $response, array $data): ApiException
    {
        $status = $response->getStatusCode();
        $message = is_string($data['error_message'] ?? null) ? $data['error_message']
            : (is_string($data['error'] ?? null) ? $data['error'] : sprintf('The API answered with HTTP %d.', $status));
        $code = is_string($data['error_code'] ?? null) ? $data['error_code'] : null;
        $errorData = is_array($data['error_data'] ?? null) ? $data['error_data'] : null;

        return match (true) {
            422 === $status || 'validation' === $code => new ValidationException($message, $status, $code, $errorData),
            404 === $status => new NotFoundException($message, $status, $code, $errorData),
            401 === $status => new AuthenticationException($message, $status, $code, $errorData),
            403 === $status => new AccessDeniedException($message, $status, $code, $errorData),
            429 === $status => new RateLimitException($message, $status, $code, $errorData, is_numeric($response->getHeaderLine('Retry-After')) ? (int)$response->getHeaderLine('Retry-After') : null),
            default => new ApiException($message, $status, $code, $errorData),
        };
    }

    private static function rateLimit(ResponseInterface $response): ?RateLimit
    {
        $limit = $response->getHeaderLine('X-RateLimit-Limit');
        if (!is_numeric($limit)) {
            return null;
        }
        return new RateLimit((int)$limit, (int)$response->getHeaderLine('X-RateLimit-Remaining'), (int)$response->getHeaderLine('X-RateLimit-Reset'));
    }
}
