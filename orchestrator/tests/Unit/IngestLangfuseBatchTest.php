<?php

namespace Tests\Unit;

use App\Jobs\IngestLangfuseBatch;
use App\Observability\LangfuseClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class IngestLangfuseBatchTest extends TestCase
{
    public function test_handle_posts_batch_to_ingestion_api(): void
    {
        config()->set('services.langfuse.public_key', 'pk');
        config()->set('services.langfuse.secret_key', 'sk');
        config()->set('services.langfuse.base_url', 'https://cloud.langfuse.com');
        config()->set('services.langfuse.enabled', true);

        $captured = [];
        $stack = HandlerStack::create(new MockHandler([new Response(207, [], '{}')]));
        $stack->push(Middleware::history($captured));
        $client = new LangfuseClient(new Client(['handler' => $stack]));

        $events = [
            ['id' => 'e1', 'type' => 'trace-create', 'timestamp' => 't', 'body' => ['id' => 'tr1']],
        ];

        (new IngestLangfuseBatch($events))->handle($client);

        $this->assertCount(1, $captured, 'expected exactly one POST to the ingestion API');
        $req = $captured[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringEndsWith('/api/public/ingestion', (string) $req->getUri());
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame(['batch' => $events], $body);
    }

    public function test_handle_noop_when_client_disabled(): void
    {
        config()->set('services.langfuse.enabled', false);

        $captured = [];
        $stack = HandlerStack::create(new MockHandler([new Response(207, [], '{}')]));
        $stack->push(Middleware::history($captured));
        $client = new LangfuseClient(new Client(['handler' => $stack]));

        $events = [
            ['id' => 'e1', 'type' => 'trace-create', 'timestamp' => 't', 'body' => []],
        ];

        (new IngestLangfuseBatch($events))->handle($client);

        $this->assertCount(0, $captured, 'disabled client must not POST');
    }
}
