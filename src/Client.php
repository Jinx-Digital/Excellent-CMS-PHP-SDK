<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

use ExcellentCms\Sdk\Auth\Authentication;
use ExcellentCms\Sdk\Exception\NotFoundException;
use ExcellentCms\Sdk\Internal\Http;
use ExcellentCms\Sdk\Internal\TokenEndpoint;
use ExcellentCms\Sdk\Internal\Transport;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Content API of one project of an Excellent CMS:
 *
 *     $cms = new Client('https://cms.example.com', 'bibliothek', new ClientCredentials($id, $secret));
 *     $books = $cms->entity('books')->where('author.name', 'Jane Austen')->get();
 *
 * The HTTP client is any PSR-18 client (Guzzle, Symfony ...) - found automatically if none is given.
 */
final class Client
{
    public const VERSION = '1.0.0';

    private Transport $transport;
    /** @var array<string, Entity> */
    private array $entities = [];
    private ?Media $media = null;

    /**
     * @param string $url address of the CMS (https://cms.example.com) or of its API (…/api/v1)
     * @param string $project slug of the project
     * @param Authentication|null $auth ClientCredentials or BearerToken - without: public entities only
     */
    public function __construct(
        string $url,
        private readonly string $project,
        ?Authentication $auth = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $http = new Http(
            $httpClient ?? Psr18ClientDiscovery::find(),
            $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
            $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            'excellent-cms-php-sdk/'.self::VERSION.' PHP/'.PHP_VERSION,
        );
        $api = self::apiUrl($url);
        $projectUrl = $api.'/'.rawurlencode($project);
        $this->transport = new Transport($http, $projectUrl, $auth, new TokenEndpoint($http, $projectUrl.'/oauth/token'));
    }

    public function project(): string
    {
        return $this->project;
    }

    /**
     * The same client in preview mode: with the token of the CMS preview (the preview address of the
     * entity passes it, e.g. ?token={{token}}), records come as drafts and working copies - for the
     * preview page of the website only, never cached:
     *
     *     $cms = $cms->preview($_GET['token'] ?? null);
     *     $page = $cms->entity('pages')->find($_GET['id']);
     *
     * Without a token (null, "") it is the normal client. An invalid or expired token makes the
     * requests fail with an AuthenticationException.
     */
    public function preview(?string $token): self
    {
        $clone = clone $this;
        $clone->transport = $this->transport->withPreview($token);
        $clone->entities = [];
        $clone->media = null;
        return $clone;
    }

    /**
     * The same client with a key of its own for the cache of the CMS - when a cache rule of the entity lets the
     * client choose (Administration › Cache). Answers are kept under the client and this key, for $ttl seconds
     * (at most what the rule allows; null: the time of the rule):
     *
     *     $home = $cms->cache('start', 600);
     *     $news = $home->entity('news')->query()->limit(5)->get();
     *     // when the start page changes: $cms->flushCache('start');
     *
     * Only reading requests send it. Without a key (null) it is the normal client.
     */
    public function cache(?string $key, ?int $ttl = null): self
    {
        if (null !== $key && 1 !== preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key)) {
            throw new Exception\InvalidArgumentException(sprintf('"%s" is no cache key: letters, digits, ".", "_", ":" and "-" (at most 100).', $key));
        }
        if (null !== $ttl && $ttl < 1) {
            throw new Exception\InvalidArgumentException('The cache time is a number of seconds (1 or more).');
        }
        $clone = clone $this;
        $clone->transport = $this->transport->withCache($key, null !== $key ? $ttl : null);
        $clone->entities = [];
        $clone->media = null;
        return $clone;
    }

    /**
     * Removes the answers the CMS keeps for this client: those of a key (see cache()), or all of them.
     *
     * @return int how many
     */
    public function flushCache(?string $key = null): int
    {
        $result = $this->transport->call('DELETE', null !== $key ? ['cache', $key] : ['cache']);
        return (int)(is_array($result['data']) ? ($result['data']['deleted'] ?? 0) : 0);
    }

    /**
     * Whether the last answer came from the cache of the CMS: "HIT", "MISS" - null when the entity is not cached.
     */
    public function cacheStatus(): ?string
    {
        return $this->transport->lastCacheStatus();
    }

    public function isPreview(): bool
    {
        return null !== $this->transport->previewToken();
    }

    /**
     * Records of an entity - no request yet.
     */
    public function entity(string $slug): Entity
    {
        return $this->entities[$slug] ??= new Entity($this->transport, $slug);
    }

    /**
     * Files of the project: upload, get, delete.
     */
    public function media(): Media
    {
        return $this->media ??= new Media($this->transport);
    }

    /**
     * Entities this client may read, with their fields.
     *
     * @return list<EntitySchema>
     */
    public function entities(): array
    {
        $data = $this->transport->call('GET', ['content'])['data'];
        return array_values(array_map(static fn(array $entity): EntitySchema => EntitySchema::fromArray($entity), array_filter(is_array($data) ? $data : [], is_array(...))));
    }

    /**
     * Fields of one entity - throws NotFoundException if the client may not read it.
     */
    public function schema(string $slug): EntitySchema
    {
        foreach ($this->entities() as $entity) {
            if ($entity->slug === $slug) {
                return $entity;
            }
        }
        throw new NotFoundException(sprintf('The entity "%s" does not exist or this client may not read it.', $slug), 404, 'not_found');
    }

    /**
     * Variables of the project ({url} ...): name => value. Query::ALL_LANGUAGES: name => [lang => value].
     *
     * @return array<string, mixed>
     */
    public function variables(?string $lang = null): array
    {
        $data = $this->transport->call('GET', ['variables'], null !== $lang ? ['lang' => $lang] : [])['data'];
        return is_array($data) ? $data : [];
    }

    /**
     * The HTML of unsaved blocks with their templates in the CMS (live editing in the preview - needs
     * the preview token): the items with "_html".
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public function renderBlocks(string $entity, string $field, array $blocks): array
    {
        $data = $this->transport->call('POST', ['content', $entity, 'render'], body: ['field' => $field, 'blocks' => $blocks])['data'];
        return array_values(array_filter(is_array($data) ? $data : [], is_array(...)));
    }

    /**
     * The public routes of a plugin in the project (/api/v1/<project>/plugins/<name>/…) - e.g.
     * sending a form of the plugin "Forms". What they take and answer is up to the plugin.
     */
    public function plugin(string $name): Plugin
    {
        return new Plugin($this->transport, $name);
    }

    /**
     * Rate limit after the last request (null: the project has none).
     */
    public function rateLimit(): ?RateLimit
    {
        return $this->transport->lastRateLimit();
    }

    private static function apiUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (!preg_match('#^https?://#i', $url)) {
            throw new Exception\InvalidArgumentException(sprintf('"%s" is no http(s) URL.', $url));
        }
        return str_ends_with($url, '/api/v1') ? $url : $url.'/api/v1';
    }
}
