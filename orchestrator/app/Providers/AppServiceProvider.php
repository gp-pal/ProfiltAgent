<?php

namespace App\Providers;

use App\IO\Contracts\LlmClient;
use App\IO\PrismLlmClient;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // LLM client is Prism (Anthropic). Bound to the contract so call sites
        // stay transport-agnostic.
        $this->app->bind(LlmClient::class, PrismLlmClient::class);

        $this->app->singleton(\App\Observability\LangfuseClient::class);
        $this->app->singleton(\App\Observability\LangfuseTracer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TLS terminates at the reverse proxy, so the app receives plain HTTP
        // and would emit http:// URLs — making Filament's login redirect loop.
        // When APP_URL is https, force generated URLs to https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // API docs (Scramble) at /docs/api + /docs/api.json. Allow the docs routes
        // outside local — the public vhost is expected to block /docs at nginx, so
        // only internal callers on the docker network read the schema. Document the
        // X-Api-Key header that protects /api/run.
        Gate::define('viewApiDocs', fn ($user = null): bool => true);
        Scramble::extendOpenApi(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::apiKey('header', 'X-Api-Key'));
        });

        // Register the read-only company-registry MariaDB as a secondary DB connection
        // without publishing the framework's config/database.php.
        config(['database.connections.company_registry' => config('company_registry.connection')]);

        // Render every Select and table SelectFilter as Filament's own anchored,
        // themed dropdown instead of a native <select>. Chrome mis-positions the
        // native option popup for appearance:none selects (it "jumps to the
        // side"); this keeps every dropdown across the panel consistent. Fields
        // may still opt back in per-call with ->native(true).
        Select::configureUsing(fn (Select $select): Select => $select->native(false));
        SelectFilter::configureUsing(fn (SelectFilter $filter): SelectFilter => $filter->native(false));
    }
}