<?php

namespace Tests\Unit;

use App\Tools\HttpArticleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class HttpArticleClientTest extends TestCase
{
    private function client(Response $response): HttpArticleClient
    {
        $mock = new MockHandler([$response]);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);

        return new HttpArticleClient($guzzle);
    }

    public function test_returns_body_on_200(): void
    {
        $out = $this->client(new Response(200, [], '<html>ok</html>'))->fetch('https://delfi.lt/a');
        $this->assertSame('<html>ok</html>', $out);
    }

    public function test_returns_null_on_non_200(): void
    {
        $this->assertNull($this->client(new Response(404, [], 'nope'))->fetch('https://delfi.lt/missing'));
    }

    public function test_returns_null_on_empty_url(): void
    {
        $this->assertNull($this->client(new Response(200, [], 'x'))->fetch('  '));
    }
}
