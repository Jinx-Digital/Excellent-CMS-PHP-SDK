<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Tests\Support;

use ExcellentCms\Sdk\Auth\Authentication;
use ExcellentCms\Sdk\Client;
use GuzzleHttp\Psr7\HttpFactory;

trait ClientFactory
{
    private function client(FakeHttpClient $http, ?Authentication $auth = null, string $url = 'https://cms.example.com'): Client
    {
        $factory = new HttpFactory();
        return new Client($url, 'bibliothek', $auth, $http, $factory, $factory);
    }
}
