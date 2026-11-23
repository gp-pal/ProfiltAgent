<?php

namespace Tests\Unit;

use App\IO\RunnerClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * The news dimension already downloads every article to read its publish date. These
 * cover handing that same HTML to the runner for text extraction, and — more
 * importantly — that losing the text never costs the run the article it found.
 */
class RunnerMarkdownTest extends TestCase
{
    private function client(Response ...$responses): RunnerClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new RunnerClient('http://runner:8080', new Client(['handler' => $stack]));
    }

    public function test_it_returns_the_converted_text(): void
    {
        $client = $this->client(new Response(200, [], json_encode([
            'ok' => true,
            'markdown' => "# Didmena uždaro parduotuvę\n\nPrekybos tinklas paskelbė.",
            'length' => 52,
        ])));

        $this->assertSame(
            "# Didmena uždaro parduotuvę\n\nPrekybos tinklas paskelbė.",
            $client->markdown('<h1>Didmena uždaro parduotuvę</h1>'),
        );
    }

    public function test_blank_html_never_reaches_the_runner(): void
    {
        // No queued response: a request would throw, which is the assertion.
        $this->assertNull($this->client()->markdown('   '));
    }

    public function test_a_runner_error_yields_null_rather_than_throwing(): void
    {
        $client = $this->client(new Response(500, [], json_encode(['ok' => false, 'error' => 'markdown_failed: boom'])));

        $this->assertNull($client->markdown('<p>x</p>'));
    }

    public function test_a_transport_failure_yields_null_rather_than_throwing(): void
    {
        $stack = HandlerStack::create(new MockHandler([new \RuntimeException('connection refused')]));
        $client = new RunnerClient('http://runner:8080', new Client(['handler' => $stack]));

        $this->assertNull($client->markdown('<p>x</p>'));
    }

    public function test_an_empty_conversion_is_reported_as_nothing_extracted(): void
    {
        // A paywalled or JS-only article converts to nothing. That must read as "no
        // text", not as an empty string the caller might store as the article body.
        $client = $this->client(new Response(200, [], json_encode(['ok' => true, 'markdown' => "   \n ", 'length' => 5])));

        $this->assertNull($client->markdown('<div id="app"></div>'));
    }
}
