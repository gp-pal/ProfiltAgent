<?php

namespace Tests\Unit;

use App\Tools\SearxngSearchService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The upstream engines behind our SearXNG rate-limit and CAPTCHA us under modest
 * automated use, and once suspended they empty every run. Since the news dimension
 * now issues 4-6 queries per company instead of 1, the client must cap its own
 * global query rate — otherwise concurrent runs knock the engines out for everyone.
 */
class SearxngThrottleTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(int $responses): SearxngSearchService
    {
        config()->set('services.searxng.url', 'http://searxng:8080');

        $body = json_encode(['results' => [
            ['title' => 'x', 'url' => 'https://delfi.lt/a/1', 'content' => 'x'],
        ]], JSON_THROW_ON_ERROR);

        $stack = HandlerStack::create(new MockHandler(
            array_fill(0, $responses, new Response(200, [], $body))
        ));
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new SearxngSearchService(new Client(['handler' => $stack]));
    }

    public function test_requests_under_the_limit_reach_searxng(): void
    {
        config()->set('services.searxng.max_per_minute', 3);
        $service = $this->service(3);

        $service->searchNews('a');
        $service->searchNews('b');

        $this->assertCount(2, $this->history);
    }

    public function test_request_over_the_limit_is_refused_without_calling_searxng(): void
    {
        config()->set('services.searxng.max_per_minute', 2);
        // Three responses are staged on purpose: an unthrottled client would happily
        // consume the third, so this fails unless the refusal is real.
        $service = $this->service(3);

        $service->searchNews('a');
        $service->searchNews('b');
        $third = $service->searchNews('c');

        // null, not []: a caller reporting what a run actually looked for has to tell a
        // refusal from a search that went out and came back empty. Reported as [], this
        // throttle silently credited people nobody had asked about.
        $this->assertNull($third);
        $this->assertCount(2, $this->history);
    }

    public function test_the_budget_is_shared_between_both_search_methods(): void
    {
        config()->set('services.searxng.max_per_minute', 2);
        $service = $this->service(3);

        $service->searchCandidates('a');
        $service->searchNews('b');
        $service->searchCandidates('c');

        // Domain resolution and the news dimension hit the same engines, so they
        // must draw from one budget rather than two.
        $this->assertCount(2, $this->history);
    }

    public function test_zero_disables_the_throttle(): void
    {
        config()->set('services.searxng.max_per_minute', 0);
        $service = $this->service(5);

        for ($i = 0; $i < 5; $i++) {
            $service->searchNews("q{$i}");
        }

        $this->assertCount(5, $this->history);
    }

    public function test_a_new_minute_restores_the_budget(): void
    {
        config()->set('services.searxng.max_per_minute', 1);
        Carbon::setTestNow('2026-08-10 10:00:30');
        $service = $this->service(2);

        $service->searchNews('a');
        $service->searchNews('b');          // refused — same minute
        $this->assertCount(1, $this->history);

        Carbon::setTestNow('2026-08-10 10:01:00');
        $service->searchNews('c');          // new window

        $this->assertCount(2, $this->history);
    }
}
