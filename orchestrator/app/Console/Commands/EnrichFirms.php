<?php

namespace App\Console\Commands;

use App\Observability\LangfuseTracer;
use App\Services\CollectorService;
use App\Tools\CompanyRegistryRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Enrich the registry "gap" firms (active, has employees, no description/contacts)
 * by running each one through the universal collector (registry DB -> official
 * site -> open web, coverage-driven) and recording the outcome in a JSON
 * manifest under runs/enrichment/{ts}/. Per-run detail lives in the registry DB.
 */
class EnrichFirms extends Command
{
    protected $signature = 'agent:enrich-firms {--with-site=5} {--without-site=5} {--dry-run} {--hard : Pick the smallest (most obscure) gap firms instead of the largest} {--output=runs/enrichment}';

    protected $description = 'Enrich registry gap firms (no description/contacts, active, has employees) via the universal collector';

    public function __construct(
        private CompanyRegistryRepository $repo,
        private CollectorService $collector,
        private LangfuseTracer $tracer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->repo->assertReachable();
            $firms = $this->repo->gapFirms((int) $this->option('with-site'), (int) $this->option('without-site'), (bool) $this->option('hard'));
        } catch (\Throwable $e) {
            $this->error('registry query failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Code', 'Name', 'Site'],
                array_map(
                    static fn (array $firm): array => [$firm['code'], $firm['name'], $firm['site'] ?? ''],
                    $firms,
                ),
            );

            return self::SUCCESS;
        }

        $ts = now()->format('Y-m-d_His');
        $entries = [];

        foreach ($firms as $firm) {
            $domain = $this->normalizeSite($firm['site']);
            $session = 'firm-' . $firm['code'];
            $target = [
                'code' => $firm['code'],
                'name' => $firm['name'],
                'address' => $firm['address'] ?? null,
                'country' => 'LT',
                'session' => $session,
                'domain' => $domain,
            ];

            // The CLI path bypasses RunController + the FlushLangfuse middleware,
            // so it must open and flush one Langfuse trace per firm itself —
            // otherwise every span/generation is a no-op and nothing is sent.
            $this->tracer->startTrace($session, [
                'company' => $firm['name'],
                'domain' => $domain,
            ], ['source' => 'agent:enrich-firms', 'code' => $firm['code']]);

            try {
                $res = $this->collector->collect($target);
            } catch (\Throwable $e) {
                $res = ['run_hash' => null, 'profile' => [], 'field_sources' => [], 'coverage' => ['overall' => 0.0], 'sources_used' => [], 'verdict' => 'error'];
                $this->tracer->span('collect-error', ['error' => $e->getMessage()])->end();
            } finally {
                $this->tracer->setTraceOutput([
                    'verdict' => $res['verdict'] ?? null,
                    'coverage' => $res['coverage']['overall'] ?? null,
                    'sources_used' => $res['sources_used'] ?? [],
                    'run_hash' => $res['run_hash'] ?? null,
                ]);
                $this->tracer->flush();
            }

            $entries[] = [
                'code' => $firm['code'],
                'name' => $firm['name'],
                'site' => $firm['site'],
                'session' => $session,
                'run_hash' => $res['run_hash'] ?? null,
                'verdict' => $res['verdict'] ?? 'error',
                'coverage' => round((float) ($res['coverage']['overall'] ?? 0.0), 3),
                'sources_used' => $res['sources_used'] ?? [],
                'field_sources' => $res['field_sources'] ?? [],
                'profile' => $this->profileSummary($res['profile'] ?? []),
            ];

            $this->line(sprintf(
                '[%s] %s -> %s (coverage %.2f, sources: %s)',
                $firm['code'],
                $firm['name'],
                $res['verdict'] ?? 'error',
                (float) ($res['coverage']['overall'] ?? 0.0),
                ($res['sources_used'] ?? []) === [] ? 'none' : implode('+', $res['sources_used']),
            ));
        }

        $verdictTally = array_count_values(array_map(static fn (array $e): string => (string) $e['verdict'], $entries));
        // 'data_limited' is reachable in CollectorService::collect() only past the
        // `sources_used === [] -> 'none'` branch, so it guarantees a source actually
        // contributed data — a firm at 5/6 declared fields is more "collected" than
        // one at 0/6, even though the former reports 'data_limited' and the latter
        // 'none'. Spelled out as an explicit list (not `!== 'none'`) so a verdict
        // added later must be counted here on purpose, not by default.
        $collected = count(array_filter(
            $entries,
            static fn (array $e): bool => in_array($e['verdict'], ['sufficient', 'partial', 'data_limited'], true),
        ));

        $manifest = [
            'run_id' => $ts,
            'generated_at' => now()->toIso8601String(),
            'criteria' => [
                'no_description' => true,
                'no_contacts' => true,
                'active' => true,
                'has_employees' => true,
            ],
            'counts' => [
                'requested' => [
                    'with_site' => (int) $this->option('with-site'),
                    'without_site' => (int) $this->option('without-site'),
                ],
                'collected' => $collected,
                'total' => count($entries),
            ],
            'verdicts' => $verdictTally,
            'firms' => $entries,
        ];

        $manifestPath = "{$this->option('output')}/{$ts}/manifest.json";
        Storage::disk('local')->put(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $this->info(sprintf(
            'Collected %d/%d. Verdicts: %s. Manifest: %s',
            $collected,
            count($entries),
            json_encode($verdictTally),
            $manifestPath,
        ));

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array{description: ?string, activity: ?string, address: ?string, emails: array<int, mixed>, phones: array<int, mixed>, social_links: array<int, mixed>}
     */
    private function profileSummary(array $profile): array
    {
        return [
            'description' => $profile['description'] ?? null,
            'activity' => $profile['activity'] ?? null,
            'address' => $profile['address'] ?? null,
            'emails' => $profile['emails'] ?? [],
            'phones' => $profile['phones'] ?? [],
            'social_links' => $profile['social_links'] ?? [],
        ];
    }

    /**
     * Reduce a registry `website` value to the bare host that runStep1Bootstrap
     * expects (its DSL template builds the URL itself as `https://{domain}`).
     */
    private function normalizeSite(?string $site): ?string
    {
        $site = trim((string) $site);

        if ($site === '') {
            return null;
        }

        $withScheme = str_contains($site, '://') ? $site : '//' . ltrim($site, '/');
        $host = parse_url($withScheme, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }

        // Fallback for values parse_url can't make sense of: strip any
        // path/query/fragment and lowercase what remains.
        $bare = preg_replace('#[/?#].*$#', '', $site);

        return strtolower((string) $bare);
    }
}
