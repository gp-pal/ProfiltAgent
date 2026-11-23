<?php

namespace Tests\Unit;

use App\IO\RunnerClient;
use App\Services\ContactsSource;
use App\Tools\SearxngSearchService;
use Mockery;
use Tests\TestCase;

class ContactsSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('react.contacts.enabled', true);
        config()->set('react.contacts.max_pages', 4);
    }

    private function searchReturning(string $url): SearxngSearchService
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchCandidates')->andReturn([]);
        $search->shouldReceive('lastResults')->andReturn([
            ['url' => $url, 'domain' => (string) parse_url($url, PHP_URL_HOST), 'title' => '', 'snippet' => '', 'accepted' => true, 'filtered' => null],
        ]);

        return $search;
    }

    private function runnerReturning(string $content): RunnerClient
    {
        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('run')->andReturn(['ok' => true, 'data' => ['content' => $content]]);

        return $runner;
    }

    public function test_extracts_emails_and_phones_from_a_page_naming_the_target(): void
    {
        $search = $this->searchReturning('https://naujienos.lt/gruzutis');
        $runner = $this->runnerReturning('Gružutis UAB kontaktai: el. paštas info@gruzutis.lt, tel. +370 600 12345, Vilnius.');

        $r = (new ContactsSource($search, $runner))->collect(['name' => 'Gružutis']);

        $this->assertSame('ok', $r->status);
        $this->assertContains('info@gruzutis.lt', $r->fields['emails']);
        $this->assertContains('+37060012345', $r->fields['phones']);
    }

    public function test_ignores_pages_that_do_not_mention_the_target(): void
    {
        $search = $this->searchReturning('https://naujienos.lt/kita');
        $runner = $this->runnerReturning('Kita įmonė, info@kita.lt, +370 600 99999.');

        $r = (new ContactsSource($search, $runner))->collect(['name' => 'Gružutis']);

        $this->assertSame('empty', $r->status);
        $this->assertSame([], $r->fields);
    }

    public function test_filters_boilerplate_emails(): void
    {
        $search = $this->searchReturning('https://naujienos.lt/gruzutis');
        $runner = $this->runnerReturning('Gružutis UAB — info@gruzutis.lt, test@example.com, noreply@gruzutis.lt');

        $r = (new ContactsSource($search, $runner))->collect(['name' => 'Gružutis']);

        $this->assertContains('info@gruzutis.lt', $r->fields['emails']);
        $this->assertNotContains('test@example.com', $r->fields['emails']);
        $this->assertNotContains('noreply@gruzutis.lt', $r->fields['emails']);
    }

    public function test_rejects_contacts_not_tied_to_the_company_name(): void
    {
        // Regression: a yellow-pages listing names the firm but the emails are the
        // directory's / another site's — they must NOT be taken as the company's.
        $search = $this->searchReturning('https://katalogas.example/imone/gruzutis');
        $runner = $this->runnerReturning('Gružutis UAB — pagalba@katalogas.example, biuras@kita.example');

        $r = (new ContactsSource($search, $runner))->collect(['name' => 'Gružutis']);

        $this->assertSame('empty', $r->status);
        $this->assertSame([], $r->fields);
    }

    public function test_rejects_false_positive_phone_shapes(): void
    {
        // Regression: digit runs like 819582014 look phone-ish but aren't valid LT
        // numbers (subscriber starts with 1) — they must be dropped.
        $search = $this->searchReturning('https://naujienos.lt/gruzutis');
        $runner = $this->runnerReturning('Gružutis UAB, kodas 819582014, nr. 819661993. Tel. +370 655 12345.');

        $r = (new ContactsSource($search, $runner))->collect(['name' => 'Gružutis']);

        // Only the valid +37065512345 survives.
        $this->assertSame(['+37065512345'], $r->fields['phones'] ?? []);
    }

    public function test_rejects_filename_like_emails(): void
    {
        // Regression (Energitra live run): a PGP key reference soc@energitra.lt.asc
        // is regex-shaped like an email and name-bound, but its final label is a
        // file extension — only the real soc@energitra.lt must survive.
        $search = $this->searchReturning('https://energitra.lt/kontaktai');
        $runner = $this->runnerReturning('Energitra kontaktai: soc@energitra.lt, PGP raktas soc@energitra.lt.asc');

        $r = (new ContactsSource($search, $runner))->collect(['name' => 'Energitra']);

        $this->assertContains('soc@energitra.lt', $r->fields['emails']);
        $this->assertNotContains('soc@energitra.lt.asc', $r->fields['emails']);
    }

    public function test_skipped_when_disabled(): void
    {
        config()->set('react.contacts.enabled', false);
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldNotReceive('searchCandidates');

        $r = (new ContactsSource($search, Mockery::mock(RunnerClient::class)))->collect(['name' => 'Gružutis']);

        $this->assertSame('skipped', $r->status);
    }

    public function test_accepts_emails_on_a_verified_domain_that_does_not_spell_the_name(): void
    {
        // A brand domain: nothing in "norvita.lt" matches "Šiaurės mažmena", so the
        // name rule alone drops the company's own address.
        $search = $this->searchReturning('https://naujienos.lt/siaures-mazmena');
        $runner = $this->runnerReturning('Šiaurės mažmena, UAB. Rašykite info@norvita.lt arba pagalba@katalogas.lt.');

        $source = new ContactsSource($search, $runner);

        $without = $source->collect(['name' => 'Šiaurės mažmena']);
        $this->assertSame('empty', $without->status);

        $with = $source->collect(['name' => 'Šiaurės mažmena', 'verified_domains' => ['norvita.lt']]);
        $this->assertSame(['info@norvita.lt'], $with->fields['emails']);
    }

    public function test_verified_domain_covers_subdomains_but_not_lookalikes(): void
    {
        $search = $this->searchReturning('https://naujienos.lt/siaures-mazmena');
        $runner = $this->runnerReturning('Šiaurės mažmena: pardavimai@b2b.norvita.lt, apgavikas@norvita.lt.evil.com, kitas@nenorvita.lt.');

        $r = (new ContactsSource($search, $runner))->collect(['name' => 'Šiaurės mažmena', 'verified_domains' => ['norvita.lt']]);

        $this->assertSame(['pardavimai@b2b.norvita.lt'], $r->fields['emails']);
    }
}
