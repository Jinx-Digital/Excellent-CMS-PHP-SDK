<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Tests\Unit;

use ExcellentCms\Sdk\Seo;
use ExcellentCms\Sdk\Tests\Support\ClientFactory;
use ExcellentCms\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class SeoTest extends TestCase
{
    use ClientFactory;

    public function testRedirectOfTheSeoPlugin(): void
    {
        $http = (new FakeHttpClient())
            ->success(['path' => '/alt', 'target' => '/neu', 'status' => 301])
            ->failure(404, 'not_found', 'No redirect for this address.');
        $seo = new Seo($this->client($http));
        $this->assertSame(['path' => '/alt', 'target' => '/neu', 'status' => 301], $seo->redirect('/alt?x=1'));
        $this->assertStringEndsWith('/api/v1/bibliothek/plugins/seo/redirect?path=%2Falt%3Fx%3D1', (string)$http->last()->getUri());
        $this->assertNull($seo->redirect('/nothing'));
    }

    public function testMetaTagsFromTheFieldGroupWithDefaults(): void
    {
        $html = Seo::meta(
            ['title' => 'Über uns', 'description' => null, 'image' => ['id' => 'm1', 'url' => 'https://cms.example.com/media/a.jpg'], 'canonical' => null, 'noindex' => true],
            ['title' => 'Ignored', 'description' => 'Wer wir sind & was wir tun', 'url' => 'https://www.example.com/ueber-uns', 'site' => 'Example'],
        );
        $this->assertStringContainsString('<title>Über uns | Example</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Wer wir sind &amp; was wir tun">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://www.example.com/ueber-uns">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://cms.example.com/media/a.jpg">', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        // Nothing set: only what the defaults give
        $this->assertStringNotContainsString('robots', Seo::meta(null, ['title' => 'Start']));
    }
}
