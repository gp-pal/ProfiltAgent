<?php

namespace App\Console\Commands;

use App\Models\CollectionRun;
use App\Models\CompanyProfile;
use App\Models\NewsPortal;
use App\Services\NewsSource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Fills an EMPTY database with invented companies, runs and news mentions, so the
 * admin panel can be shown (screenshots, a demo) without any real company in it.
 *
 * Every name, code, domain, address, person and article here is made up; hosts use
 * the reserved `.example` TLD. Refuses to run against a database that already holds
 * runs or profiles, because it is not meant to be mixed with collected data.
 */
class SeedDemo extends Command
{
    protected $signature = 'agent:seed-demo';

    protected $description = 'Fill an empty database with invented demo companies, runs and news';

    public function handle(): int
    {
        if (CollectionRun::query()->exists() || CompanyProfile::query()->exists()) {
            $this->error('The database already holds runs or profiles; demo data is only seeded into an empty one.');

            return self::FAILURE;
        }

        NewsPortal::query()->delete();
        foreach (['naujienos.example' => 'Naujienos', 'verslas.example' => 'Verslo diena', 'regionai.example' => 'Regionų žinios'] as $domain => $label) {
            NewsPortal::create(['domain' => $domain, 'label' => $label, 'enabled' => true]);
        }

        $now = Carbon::now();

        foreach ($this->companies() as $i => $c) {
            $key = 'code:' . $c['code'];
            $at = $now->copy()->subDays($c['days_ago'])->subMinutes(7 * $i);

            $verification = [];
            foreach ($c['sources'] as $field => $source) {
                $verification[$field] = [
                    'status' => $c['verified'] ? CompanyProfile::VERIFICATION_VERIFIED : CompanyProfile::VERIFICATION_UNVERIFIED,
                    'source' => $source,
                    'verdict' => $c['verified'] ? 'pass' : 'warn',
                    'basis' => 'judge',
                    'at' => $at->toIso8601String(),
                ];
            }

            $fields = $c['fields'];
            if ($c['mentions'] !== []) {
                $fields['news'] = ['score' => $c['news_score'], 'fresh' => true, 'mentions' => $c['mentions']];
            }

            CompanyProfile::create([
                'company_key' => $key,
                'company_code' => $c['code'],
                'name' => $c['name'],
                'domain' => $c['domain'],
                'fields' => $fields,
                'field_coverage' => array_fill_keys(array_keys($c['fields']), 1.0),
                'field_sources' => $c['sources'],
                'field_verification' => $verification,
                'overall_score' => $c['score'],
            ]);

            $run = CollectionRun::create([
                'run_hash' => (string) Str::uuid(),
                'company_key' => $key,
                'company_code' => $c['code'],
                'name' => $c['name'],
                'session' => null,
                'sources_used' => $c['sources_used'],
                'overall_score' => $c['score'],
                'verdict' => $c['verdict'],
                'status' => $c['status'],
                'started_at' => $at,
                'finished_at' => $at->copy()->addSeconds(38 + 9 * $i),
            ]);
            $run->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            foreach ($c['actions'] as $action) {
                $run->actions()->create(['action' => 'collect', ...$action]);
            }
        }

        $this->info('Demo data seeded.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function companies(): array
    {
        $match = static fn (float $confidence): array => ['match' => [
            'accepted' => true, 'verdict' => 'pass', 'basis' => 'judge', 'verified' => true,
            'derived_from' => null, 'match_confidence' => $confidence, 'data_coverage' => 0.8,
            'reasons' => ['Company name matches', 'Registry code found on the page', 'Lithuanian address and +370 phone numbers'],
        ]];
        $news = static fn (array $mentions, float $score, string $state = NewsSource::SEARCH_ANSWERED): string => (string) json_encode(['news' => [
            'score' => $score, 'fresh' => $mentions !== [], 'mentions' => $mentions,
            'search_state' => $state, 'people_searched' => [], 'people_dropped' => [], 'people_dropped_reasons' => [],
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $detail = static fn (array $d): string => (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $date = static fn (int $daysAgo): string => Carbon::now()->subDays($daysAgo)->format('Y-m-d');

        $azernaMentions = [
            ['portal' => 'verslas.example', 'title' => '„Azerna“ atidaro antrą gamybos cechą Kaune', 'url' => 'https://verslas.example/imones/azerna-antras-cechas', 'date' => $date(9), 'subject' => 'company', 'person' => null],
            ['portal' => 'naujienos.example', 'title' => 'Fasadų gamintoja „Azerna“ pernai augino pardavimus penktadaliu', 'url' => 'https://naujienos.example/verslas/azerna-pardavimai', 'date' => $date(41), 'subject' => 'company', 'person' => null],
            ['portal' => 'regionai.example', 'title' => '„Azerna“ ieško trisdešimties naujų darbuotojų', 'url' => 'https://regionai.example/kaunas/azerna-darbuotojai', 'date' => $date(88), 'subject' => 'company', 'person' => null],
        ];
        $girluneMentions = [
            ['portal' => 'naujienos.example', 'title' => 'Kepykla „Girlūnė“ plečia parduotuvių tinklą Žemaitijoje', 'url' => 'https://naujienos.example/verslas/girlune-tinklas', 'date' => $date(20), 'subject' => 'company', 'person' => null],
        ];

        return [
            [
                'code' => '300000101', 'name' => 'Azerna, UAB', 'domain' => 'azerna.example', 'days_ago' => 0,
                'score' => 1.0, 'verdict' => 'sufficient', 'status' => 'done', 'verified' => true,
                'sources_used' => ['official_site', 'open_web', 'contacts', 'news'],
                'fields' => [
                    'description' => 'Azerna projektuoja, gamina ir montuoja aliuminio ir stiklo fasadus, langus bei duris komerciniams pastatams visoje Lietuvoje.',
                    'activity' => 'Aliuminio ir stiklo fasadų gamyba ir montavimas',
                    'address' => 'Pramonės pr. 14, Kaunas',
                    'emails' => ['info@azerna.example', 'pardavimai@azerna.example'],
                    'phones' => ['+37037000101'],
                    'social_links' => ['https://linkedin.example/company/azerna'],
                ],
                'sources' => ['description' => 'official_site', 'activity' => 'official_site', 'emails' => 'official_site', 'phones' => 'official_site', 'social_links' => 'official_site', 'address' => 'open_web'],
                'mentions' => $azernaMentions, 'news_score' => 1.0,
                'actions' => [
                    ['source' => 'registry_db', 'status' => 'skipped'],
                    ['source' => 'official_site', 'status' => 'ok', 'yielded' => ['activity' => 'Aliuminio ir stiklo fasadų gamyba ir montavimas', 'emails' => ['info@azerna.example']], 'field_scores' => ['description' => 1, 'activity' => 1, 'emails' => 1, 'phones' => 1, 'address' => 0, 'social_links' => 1], 'detail' => $detail(['domain' => 'azerna.example', 'resolved_via' => 'search', ...$match(0.97)])],
                    ['source' => 'open_web', 'status' => 'ok', 'yielded' => ['address' => 'Pramonės pr. 14, Kaunas'], 'field_scores' => ['description' => 1, 'activity' => 1, 'emails' => 0, 'phones' => 1, 'address' => 1, 'social_links' => 0], 'detail' => $detail(['pages' => ['https://katalogas.example/imone/300000101'], ...$match(0.95)])],
                    ['source' => 'contacts', 'status' => 'ok', 'yielded' => ['emails' => ['info@azerna.example', 'pardavimai@azerna.example'], 'phones' => ['+37037000101']], 'field_scores' => ['emails' => 1, 'phones' => 1], 'detail' => $detail(['pages' => ['https://azerna.example/kontaktai'], ...$match(0.96)])],
                    ['source' => 'news', 'status' => 'ok', 'detail' => $news($azernaMentions, 1.0)],
                ],
            ],
            [
                'code' => '300000202', 'name' => 'Girlūnė, UAB', 'domain' => 'girlune.example', 'days_ago' => 1,
                'score' => 0.83, 'verdict' => 'partial', 'status' => 'done', 'verified' => true,
                'sources_used' => ['official_site', 'open_web', 'news'],
                'fields' => [
                    'description' => 'Girlūnė – šeimos kepykla, kasdien kepanti ruginę duoną ir konditerijos gaminius pagal senus žemaitiškus receptus.',
                    'activity' => 'Duonos ir konditerijos gaminių gamyba',
                    'address' => 'Turgaus g. 3, Telšiai',
                    'phones' => ['+37044400202'],
                    'social_links' => ['https://facebook.example/girlune'],
                ],
                'sources' => ['description' => 'official_site', 'activity' => 'official_site', 'phones' => 'official_site', 'social_links' => 'official_site', 'address' => 'open_web'],
                'mentions' => $girluneMentions, 'news_score' => 0.33,
                'actions' => [
                    ['source' => 'registry_db', 'status' => 'skipped'],
                    ['source' => 'official_site', 'status' => 'ok', 'yielded' => ['activity' => 'Duonos ir konditerijos gaminių gamyba'], 'field_scores' => ['description' => 1, 'activity' => 1, 'emails' => 0, 'phones' => 1, 'address' => 0, 'social_links' => 1], 'detail' => $detail(['domain' => 'girlune.example', 'resolved_via' => 'search', ...$match(0.93)])],
                    ['source' => 'open_web', 'status' => 'ok', 'yielded' => ['address' => 'Turgaus g. 3, Telšiai'], 'field_scores' => ['address' => 1], 'detail' => $detail(['pages' => ['https://katalogas.example/imone/300000202'], ...$match(0.9)])],
                    ['source' => 'contacts', 'status' => 'error', 'detail' => $detail(['detail' => 'search backend did not answer'])],
                    ['source' => 'news', 'status' => 'ok', 'detail' => $news($girluneMentions, 0.33)],
                ],
            ],
            [
                'code' => '300000303', 'name' => 'Vėjūnas ir partneriai, MB', 'domain' => null, 'days_ago' => 2,
                'score' => 0.5, 'verdict' => 'data_limited', 'status' => 'done', 'verified' => true,
                'sources_used' => ['open_web'],
                'fields' => [
                    'description' => 'Small accounting practice serving sole traders and small partnerships in the Panevėžys region.',
                    'activity' => 'Accounting and bookkeeping services',
                    'address' => 'Respublikos g. 71, Panevėžys',
                ],
                'sources' => ['description' => 'open_web', 'activity' => 'open_web', 'address' => 'open_web'],
                'mentions' => [], 'news_score' => 0.0,
                'actions' => [
                    ['source' => 'registry_db', 'status' => 'skipped'],
                    ['source' => 'official_site', 'status' => 'empty', 'detail' => $detail(['domain' => null, 'error' => null])],
                    ['source' => 'open_web', 'status' => 'ok', 'yielded' => ['address' => 'Respublikos g. 71, Panevėžys'], 'field_scores' => ['description' => 1, 'activity' => 1, 'address' => 1], 'detail' => $detail(['pages' => ['https://katalogas.example/imone/300000303'], ...$match(0.91)])],
                    ['source' => 'contacts', 'status' => 'empty', 'detail' => $detail(['detail' => 'no contact pages found'])],
                    ['source' => 'news', 'status' => 'error', 'detail' => $news([], 0.0, NewsSource::SEARCH_OFF_PORTAL)],
                ],
            ],
            [
                'code' => '300000404', 'name' => 'Šilbalė, AB', 'domain' => 'silbale.example', 'days_ago' => 3,
                'score' => 0.67, 'verdict' => 'partial', 'status' => 'done', 'verified' => false,
                'sources_used' => ['official_site'],
                'fields' => [
                    'description' => 'Šilbalė gamina medienos granules ir biokuro briketus, tiekiamus katilinėms ir namų ūkiams.',
                    'activity' => 'Biokuro gamyba',
                    'emails' => ['uzsakymai@silbale.example'],
                    'phones' => ['+37046000404'],
                ],
                'sources' => ['description' => 'official_site', 'activity' => 'official_site', 'emails' => 'official_site', 'phones' => 'official_site'],
                'mentions' => [], 'news_score' => 0.0,
                'actions' => [
                    ['source' => 'registry_db', 'status' => 'skipped'],
                    ['source' => 'official_site', 'status' => 'ok', 'yielded' => ['activity' => 'Biokuro gamyba'], 'field_scores' => ['description' => 1, 'activity' => 1, 'emails' => 1, 'phones' => 1, 'address' => 0, 'social_links' => 0], 'detail' => $detail(['domain' => 'silbale.example', 'match' => ['accepted' => true, 'verdict' => 'warn', 'basis' => 'heuristic', 'verified' => false, 'derived_from' => null, 'match_confidence' => null, 'data_coverage' => null, 'reasons' => ['Match judge unavailable; accepted on name and domain overlap']]])],
                    ['source' => 'open_web', 'status' => 'rejected', 'detail' => $detail(['pages' => ['https://zinynas.example/silbale'], 'match' => ['accepted' => false, 'verdict' => 'fail', 'basis' => 'judge', 'verified' => true, 'derived_from' => null, 'match_confidence' => 0.2, 'data_coverage' => 0.4, 'reasons' => ['Page describes a village of the same name, not the company']]])],
                    ['source' => 'contacts', 'status' => 'empty', 'detail' => $detail(['detail' => 'no contact pages found'])],
                    ['source' => 'news', 'status' => 'empty', 'detail' => $news([], 0.0)],
                ],
            ],
            [
                'code' => '300000505', 'name' => 'Tauragnė, UAB', 'domain' => null, 'days_ago' => 4,
                'score' => 0.0, 'verdict' => 'none', 'status' => 'failed', 'verified' => false,
                'sources_used' => [],
                'fields' => [],
                'sources' => [],
                'mentions' => [], 'news_score' => 0.0,
                'actions' => [
                    ['source' => 'registry_db', 'status' => 'skipped'],
                    ['source' => 'official_site', 'status' => 'error', 'detail' => $detail(['detail' => 'runner unreachable'])],
                ],
            ],
        ];
    }
}
