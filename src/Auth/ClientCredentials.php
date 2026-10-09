<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Auth;

use ExcellentCms\Sdk\Internal\TokenEndpoint;
use Psr\SimpleCache\CacheInterface;

/**
 * OAuth 2.0 client credentials of an API client ("API-Zugänge" in the admin app). The token is
 * fetched on the first request, kept until shortly before it expires and fetched again after a
 * 401 (e.g. when the client's access was changed).
 *
 * Pass a PSR-16 cache to share the token between PHP requests - otherwise every PHP process
 * fetches its own.
 */
final class ClientCredentials implements Authentication
{
    private ?AccessToken $token = null;
    private ?string $lastKey = null;

    /**
     * @param list<string> $scopes entity slugs the token is limited to (empty: all of the client)
     * @param (\Closure(): int)|null $clock current unix time (tests)
     */
    public function __construct(
        private readonly string $clientId,
        #[\SensitiveParameter]
        private readonly string $clientSecret,
        private readonly array $scopes = [],
        private readonly ?CacheInterface $cache = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function token(TokenEndpoint $endpoint): string
    {
        $now = $this->now();
        if (null !== $this->token && !$this->token->isExpired($now)) {
            return $this->token->value;
        }

        $key = $this->cacheKey($endpoint);
        // Stored as an array: every cache can keep it, also those that do not serialize objects
        $cached = $this->cache?->get($key);
        if (is_array($cached) && is_string($cached['value'] ?? null) && is_int($cached['expires_at'] ?? null)) {
            $token = new AccessToken($cached['value'], $cached['expires_at'], is_string($cached['scope'] ?? null) ? $cached['scope'] : null);
            if (!$token->isExpired($now)) {
                return ($this->token = $token)->value;
            }
        }

        $this->token = $endpoint->request($this->clientId, $this->clientSecret, [] !== $this->scopes ? implode(' ', $this->scopes) : null, $now);
        $this->cache?->set($key, ['value' => $this->token->value, 'expires_at' => $this->token->expiresAt, 'scope' => $this->token->scope], max(1, $this->token->expiresAt - $now));
        return $this->token->value;
    }

    public function invalidate(): bool
    {
        // Without a token of our own there is nothing a new one could change
        $hadToken = null !== $this->token;
        $this->token = null;
        if ($hadToken && null !== $this->lastKey) {
            $this->cache?->delete($this->lastKey);
        }
        return $hadToken;
    }

    private function cacheKey(TokenEndpoint $endpoint): string
    {
        // PSR-16 keys: no {}()/\@: - a hash of everything that makes the token different
        return $this->lastKey = 'excellent_cms_token_'.hash('sha256', implode("\n", [$endpoint->url(), $this->clientId, $this->clientSecret, implode(' ', $this->scopes)]));
    }

    private function now(): int
    {
        return null !== $this->clock ? ($this->clock)() : time();
    }
}
