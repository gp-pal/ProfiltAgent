<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureApiKey;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureApiKeyTest extends TestCase
{
    private function invokeMiddleware(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return (new EnsureApiKey())->handle($request, fn () => response('ok', 200));
    }

    public function test_blocks_when_key_configured_but_missing(): void
    {
        config(['agent.api_key' => 'secret']);

        $this->assertSame(401, $this->invokeMiddleware(Request::create('/api/run', 'POST'))->getStatusCode());
    }

    public function test_blocks_on_wrong_key(): void
    {
        config(['agent.api_key' => 'secret']);
        $req = Request::create('/api/run', 'POST');
        $req->headers->set('X-Api-Key', 'wrong');

        $this->assertSame(401, $this->invokeMiddleware($req)->getStatusCode());
    }

    public function test_allows_with_correct_key_header(): void
    {
        config(['agent.api_key' => 'secret']);
        $req = Request::create('/api/run', 'POST');
        $req->headers->set('X-Api-Key', 'secret');

        $this->assertSame(200, $this->invokeMiddleware($req)->getStatusCode());
    }

    public function test_allows_with_bearer_token(): void
    {
        config(['agent.api_key' => 'secret']);
        $req = Request::create('/api/run', 'POST');
        $req->headers->set('Authorization', 'Bearer secret');

        $this->assertSame(200, $this->invokeMiddleware($req)->getStatusCode());
    }

    public function test_allows_when_unconfigured_in_local_or_testing(): void
    {
        config(['agent.api_key' => '']);
        $this->app['env'] = 'testing';

        $this->assertSame(200, $this->invokeMiddleware(Request::create('/api/run', 'POST'))->getStatusCode());
    }

    public function test_denies_when_unconfigured_outside_local_testing(): void
    {
        // Fail closed: an unset secret must not leave a networked deployment open.
        config(['agent.api_key' => '']);
        $this->app['env'] = 'production';

        $this->assertSame(503, $this->invokeMiddleware(Request::create('/api/run', 'POST'))->getStatusCode());
    }
}
