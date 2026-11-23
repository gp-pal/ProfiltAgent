<?php

namespace Tests\Unit;

use App\Observability\LangfuseClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class LangfuseClientTest extends TestCase
{
    private function configured(): void
    {
        config()->set('services.langfuse.public_key', 'pk-test');
        config()->set('services.langfuse.secret_key', 'sk-test');
        config()->set('services.langfuse.base_url', 'https://cloud.langfuse.com');
        config()->set('services.langfuse.enabled', true);
    }

    public function test_enabled_requires_keys(): void
    {
        config()->set('services.langfuse.public_key', '');
        config()->set('services.langfuse.secret_key', '');
        $this->assertFalse((new LangfuseClient())->enabled());

        $this->configured();
        $this->assertTrue((new LangfuseClient())->enabled());
    }

    public function test_ingest_posts_batch_with_basic_auth(): void
    {
        $this->configured();
        $captured = [];
        $stack = HandlerStack::create(new MockHandler([new Response(207, [], '{}')]));
        $stack->push(Middleware::history($captured));
        $client = new Client(['handler' => $stack]);

        (new LangfuseClient($client))->ingest([['id' => 'e1', 'type' => 'trace-create', 'timestamp' => 't', 'body' => ['id' => 'tr1']]]);

        $this->assertCount(1, $captured);
        $req = $captured[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringEndsWith('/api/public/ingestion', (string) $req->getUri());
        $this->assertStringStartsWith('Basic ', $req->getHeaderLine('Authorization'));
        $this->assertSame(base64_encode('pk-test:sk-test'), substr($req->getHeaderLine('Authorization'), 6));
        $body = json_decode((string) $req->getBody(), true);
        $this->assertArrayHasKey('batch', $body);
        $this->assertSame('trace-create', $body['batch'][0]['type']);
    }

    public function test_ingest_swallows_errors(): void
    {
        $this->configured();
        $stack = HandlerStack::create(new MockHandler([
            new \GuzzleHttp\Exception\ConnectException('boom', new \GuzzleHttp\Psr7\Request('POST', 'x')),
        ]));
        $client = new Client(['handler' => $stack]);

        // Must NOT throw.
        (new LangfuseClient($client))->ingest([['id' => 'e1', 'type' => 'trace-create', 'timestamp' => 't', 'body' => []]]);
        $this->assertTrue(true);
    }

    public function test_ingest_noop_when_disabled(): void
    {
        config()->set('services.langfuse.enabled', false);
        $captured = [];
        $stack = HandlerStack::create(new MockHandler([new Response(207, [], '{}')]));
        $stack->push(Middleware::history($captured));
        $client = new Client(['handler' => $stack]);

        (new LangfuseClient($client))->ingest([['id' => 'e1', 'type' => 'trace-create', 'timestamp' => 't', 'body' => []]]);
        $this->assertCount(0, $captured, 'disabled client must not POST');
    }
}
