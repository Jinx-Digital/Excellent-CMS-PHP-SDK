<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Tests\Unit;

use ExcellentCms\Sdk\Auth\BearerToken;
use ExcellentCms\Sdk\Auth\ClientCredentials;
use ExcellentCms\Sdk\Exception\AuthenticationException;
use ExcellentCms\Sdk\Exception\OAuthException;
use ExcellentCms\Sdk\Tests\Support\ArrayCache;
use ExcellentCms\Sdk\Tests\Support\ClientFactory;
use ExcellentCms\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    use ClientFactory;

    private int $now = 1_000_000;

    private function credentials(?ArrayCache $cache = null, array $scopes = []): ClientCredentials
    {
        return new ClientCredentials('client-1', 's3cret', $scopes, $cache, fn(): int => $this->now);
    }

    public function testTokenIsFetchedOnceAndSent(): void
    {
        $http = (new FakeHttpClient())->token('token-1')->success([])->success([]);
        $cms = $this->client($http, $this->credentials(scopes: ['books', 'authors']));

        $cms->entities();
        $cms->entities();

        $this->assertCount(3, $http->requests);
        $tokenRequest = $http->requests[0];
        $this->assertSame(['POST', 'https://cms.example.com/api/v1/bibliothek/oauth/token'], [$tokenRequest->getMethod(), (string)$tokenRequest->getUri()]);
        $this->assertSame('application/x-www-form-urlencoded', $tokenRequest->getHeaderLine('Content-Type'));
        parse_str((string)$tokenRequest->getBody(), $form);
        $this->assertSame(['grant_type' => 'client_credentials', 'client_id' => 'client-1', 'client_secret' => 's3cret', 'scope' => 'books authors'], $form);
        $this->assertSame('Bearer token-1', $http->requests[1]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer token-1', $http->requests[2]->getHeaderLine('Authorization'));
    }

    public function testTokenIsRenewedShortlyBeforeItExpires(): void
    {
        $http = (new FakeHttpClient())->token('token-1', 300)->success([])->token('token-2')->success([]);
        $cms = $this->client($http, $this->credentials());

        $cms->entities();
        $this->now += 280; // 20 seconds left - too little for a request
        $cms->entities();

        $this->assertSame('Bearer token-2', $http->last()->getHeaderLine('Authorization'));
    }

    public function testRefusedTokenIsRenewedOnce(): void
    {
        $http = (new FakeHttpClient())
            ->token('revoked')->failure(401, 'unauthorized')
            ->token('token-2')->success([])
            ->failure(401, 'unauthorized')->token('token-3')->failure(401, 'unauthorized');
        $cms = $this->client($http, $this->credentials());

        $cms->entities();
        $this->assertSame('Bearer token-2', $http->last()->getHeaderLine('Authorization'));

        // Still refused with a fresh token: no endless loop
        $this->expectException(AuthenticationException::class);
        $cms->entities();
    }

    public function testCacheSharesTheTokenBetweenClients(): void
    {
        $cache = new ArrayCache();
        $http = (new FakeHttpClient())->token('shared')->success([])->success([]);

        $this->client($http, $this->credentials($cache))->entities();
        $this->client($http, $this->credentials($cache))->entities();

        $this->assertCount(3, $http->requests, 'the second client takes the cached token');
        $this->assertSame('Bearer shared', $http->last()->getHeaderLine('Authorization'));
        $this->assertSame(['value' => 'shared', 'expires_at' => $this->now + 3600, 'scope' => 'books'], array_values($cache->items)[0]);
        $this->assertDoesNotMatchRegularExpression('/[{}()\/\\\\@:]/', array_key_first($cache->items), 'valid PSR-16 key');
        $this->assertStringNotContainsString('s3cret', serialize($cache->items));
    }

    public function testWrongCredentials(): void
    {
        $http = (new FakeHttpClient())->json(['error' => 'invalid_client', 'error_description' => 'Client authentication failed.'], 401);

        try {
            $this->client($http, $this->credentials())->entities();
            $this->fail('no exception');
        } catch (OAuthException $e) {
            $this->assertSame(['invalid_client', 'Client authentication failed.', 401], [$e->error(), $e->getMessage(), $e->status()]);
        }
    }

    public function testBearerTokenIsSentAsItIs(): void
    {
        $http = (new FakeHttpClient())->success([])->failure(401, 'unauthorized');
        $cms = $this->client($http, new BearerToken('own-token'));

        $cms->entities();
        $this->assertSame('Bearer own-token', $http->last()->getHeaderLine('Authorization'));

        $this->expectException(AuthenticationException::class);
        $cms->entities();
    }
}
