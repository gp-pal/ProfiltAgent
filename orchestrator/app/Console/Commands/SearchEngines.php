<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

/**
 * Report which SearXNG engines are actually answering, and — separately — which of
 * them honour the `site:` operator.
 *
 * Those are two different questions and conflating them cost this project a day. The
 * whole news strategy is built on `site:` scoping, so an engine that answers plain
 * queries but returns nothing for `site:` leaves the news layer blind while every
 * health check stays green: the service is up, it responds 200 in three seconds, and
 * it returns an empty list.
 */
class SearchEngines extends Command
{
    protected $signature = 'agent:search-engines
        {--term=bankas : A word that certainly has results}
        {--site=delfi.lt : Domain used to test the site: operator}
        {--timeout=30 : Seconds per query}
        {--samples=1 : Repeat every probe this many times}
        {--delay=8 : Seconds between samples, so the probe is not itself the rate-limit cause}';

    protected $description = 'Show which SearXNG engines answer, and which of them support the site: operator.';

    public function handle(): int
    {
        $base = rtrim((string) config('services.searxng.url', ''), '/');
        if ($base === '') {
            $this->error('services.searxng.url is not configured.');

            return self::FAILURE;
        }

        $term = (string) $this->option('term');
        $site = (string) $this->option('site');
        $client = new Client(['timeout' => (int) $this->option('timeout')]);

        $probes = [
            'plain' => $term,
            'quoted' => "\"{$term}\"",
            'site:' => "{$term} site:{$site}",
            'quoted + site: group' => "\"{$term}\" (site:{$site})",
        ];

        $this->line("SearXNG: {$base}");
        $this->newLine();

        $samples = max(1, (int) $this->option('samples'));
        $delay = max(0, (int) $this->option('delay'));
        $rows = [];
        $siteWorks = false;

        if ($samples > 1) {
            $this->line("Sampling each probe {$samples}x, {$delay}s apart.");
            $this->newLine();
        }

        foreach ($probes as $label => $query) {
            $engines = [];
            $counts = [];
            $errors = 0;

            for ($sample = 0; $sample < $samples; $sample++) {
                if ($sample > 0 && $delay > 0) {
                    sleep($delay);
                }

                [$count, $sampleEngines, $error] = $this->probe($client, $base, $query);
                $counts[] = $count;
                if ($error !== null) {
                    $errors++;
                }
                foreach ($sampleEngines as $engine => $n) {
                    $engines[$engine] = ($engines[$engine] ?? 0) + $n;
                }
            }

            $nonZero = count(array_filter($counts, static fn (int $c): bool => $c > 0));
            if (str_contains($label, 'site:') && $nonZero > 0) {
                $siteWorks = true;
            }

            arsort($engines);
            $rows[] = [
                $label,
                sprintf('%d/%d', $nonZero, $samples),
                max($counts) . '/' . min($counts),
                $errors > 0 ? (string) $errors : '-',
                $engines === [] ? '—' : implode(', ', array_map(
                    static fn (string $e, int $n): string => "{$e}({$n})",
                    array_keys($engines),
                    $engines,
                )),
            ];
        }

        $this->table(['probe', 'samples with results', 'max/min', 'errors', 'engines seen'], $rows);
        $this->newLine();

        if (! $siteWorks) {
            $this->error('site: returned nothing in every sample — the news layer is blind.');
            $this->line('Plain queries succeeding while site: never does means the engines that honour the');
            $this->line('operator are suspended. Dropping site: does not help: without it the whitelisted');
            $this->line('portals do not surface at all.');

            return self::FAILURE;
        }

        $this->info('site: produced results in at least one sample — the layer can see, intermittently.');
        $this->line('A ratio below samples/samples means the news dimension will keep flapping between');
        $this->line('finding articles and reporting error, on a timescale of about a minute.');

        return self::SUCCESS;
    }

    /**
     * One request. Returns the result count, the per-engine tally, and an error string.
     *
     * @return array{0: int, 1: array<string, int>, 2: ?string}
     */
    private function probe(Client $client, string $base, string $query): array
    {
        $engines = [];
        $count = 0;

        try {
            $response = $client->get("{$base}/search", [
                'query' => ['q' => $query, 'format' => 'json', 'categories' => 'general', 'language' => 'lt'],
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [0, [], 'HTTP ' . $response->getStatusCode()];
            }

            $data = json_decode((string) $response->getBody(), true) ?: [];
            foreach (($data['results'] ?? []) as $r) {
                $count++;
                $engine = (string) ($r['engine'] ?? '?');
                $engines[$engine] = ($engines[$engine] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            return [0, [], mb_substr($e->getMessage(), 0, 40)];
        }

        return [$count, $engines, null];
    }
}
