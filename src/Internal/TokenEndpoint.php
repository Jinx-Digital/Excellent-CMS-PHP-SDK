<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Internal;

use ExcellentCms\Sdk\Auth\AccessToken;
use ExcellentCms\Sdk\Exception\OAuthException;

/**
 * POST /api/v1/{project}/oauth/token - client credentials grant (RFC 6749 section 4.4).
 *
 * @internal
 */
final class TokenEndpoint
{
    public function __construct(
        private readonly Http $http,
        private readonly string $url,
    ) {
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @param int $now unix time of the caller's clock - the token expires relative to it
     */
    public function request(string $clientId, #[\SensitiveParameter] string $clientSecret, ?string $scope, int $now): AccessToken
    {
        $form = ['grant_type' => 'client_credentials', 'client_id' => $clientId, 'client_secret' => $clientSecret];
        if (null !== $scope) {
            $form['scope'] = $scope;
        }
        $request = $this->http->request('POST', $this->url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->http->streams->createStream(http_build_query($form, '', '&', PHP_QUERY_RFC3986)));
        $response = $this->http->send($request);
        $data = Http::json($response);

        if (200 !== $response->getStatusCode() || !is_string($data['access_token'] ?? null)) {
            throw new OAuthException(
                is_string($data['error'] ?? null) ? $data['error'] : 'invalid_response',
                is_string($data['error_description'] ?? null) ? $data['error_description'] : sprintf('The token endpoint answered with HTTP %d.', $response->getStatusCode()),
                $response->getStatusCode(),
            );
        }
        return new AccessToken($data['access_token'], $now + (is_int($data['expires_in'] ?? null) ? $data['expires_in'] : 3600), is_string($data['scope'] ?? null) ? $data['scope'] : null);
    }
}
