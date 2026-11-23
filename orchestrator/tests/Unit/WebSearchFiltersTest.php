<?php

namespace Tests\Unit;

use App\Tools\WebSearchFilters;
use Tests\TestCase;

class WebSearchFiltersTest extends TestCase
{
    /**
     * @return array<int, array{0: string}>
     */
    public static function aggregatorDomains(): array
    {
        return [
            ['scoris.lt'],
            ['www.scoris.lt'],
            ['okredo.com'],
            ['okredo.lt'],
            ['rekvizitai.lt'],
            ['imones.lt'],
            ['b-a.lt'],
            ['visalietuva.lt'],
            ['facebook.com'],
            ['linkedin.com'],
        ];
    }

    /**
     * @dataProvider aggregatorDomains
     */
    public function test_known_aggregators_are_rejected(string $domain): void
    {
        $this->assertTrue(WebSearchFilters::isDirectoryOrAggregator($domain), "{$domain} must be treated as an aggregator");
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function realCompanyDomains(): array
    {
        return [
            ['pergola.com'],
            ['smarteimis.com'],
            ['hausta.lt'],
            ['telvona.lt'],
            ['lrt.lt'],
            // Collision regressions: substring matching used to false-block these.
            ['web-a.lt'],          // must NOT match host pattern 'b-a.lt'
            ['notfacebook.com'],   // must NOT match host pattern 'facebook.com'
            ['matrix.com'],        // must NOT match host pattern 'x.com'
        ];
    }

    /**
     * @dataProvider realCompanyDomains
     */
    public function test_real_company_domains_are_allowed(string $domain): void
    {
        $this->assertFalse(WebSearchFilters::isDirectoryOrAggregator($domain), "{$domain} must NOT be treated as an aggregator");
    }

    /**
     * Domains never worth scraping as an OPEN informational source: the state
     * register's own site, login-walled social networks, and product classifieds
     * (car/real-estate ads — noise, not company data).
     *
     * @return array<int, array{0: string}>
     */
    public static function nonScrapeableOpenSources(): array
    {
        return [
            // The state register's own site.
            ['registrucentras.lt'],
            // A directory that answers automated readers with a bot challenge.
            ['okredo.com'],
            ['okredo.lt'],
            ['rekvizitai.lt'],
            ['www.rekvizitai.lt'],
            ['scoris.lt'],
            ['www.scoris.lt'],
            ['rc.lt'],
            // Login-walled / ToS social networks.
            ['facebook.com'],
            ['linkedin.com'],
            ['instagram.com'],
            ['x.com'],
            ['twitter.com'],
            ['youtube.com'],
            // Product classifieds — noise, not a company profile.
            ['aruodas.lt'],
            ['autoplius.lt'],
        ];
    }

    /**
     * @dataProvider nonScrapeableOpenSources
     */
    public function test_open_source_skips_register_social_and_classifieds(string $domain): void
    {
        $this->assertFalse(WebSearchFilters::isScrapeableOpenSource($domain), "{$domain} must NOT be scraped as an open source");
    }

    /**
     * Third-party business/credit directories and job boards DO carry useful
     * data (contacts, descriptions firms submit), so they ARE scraped as
     * open sources — critical for firms with no official site. Wikipedia, news
     * and general web are allowed too.
     *
     * @return array<int, array{0: string}>
     */
    public static function scrapeableOpenSources(): array
    {
        return [
            // Third-party business / credit directories.
            ['imones.lt'],
            ['visalietuva.lt'],
            ['118.lt'],
            ['b-a.lt'],
            // Job boards — postings carry "about the company" blurbs.
            ['cvmarket.lt'],
            ['cvbankas.lt'],
            // Encyclopedic / news / general web.
            ['lt.wikipedia.org'],
            ['en.wikipedia.org'],
            ['lrt.lt'],
            ['example.com'],
            // Collision regressions: short patterns must not over-block.
            ['marc.lt'],         // must NOT match host 'rc.lt'
            ['notfacebook.com'], // must NOT match 'facebook.com'
            ['matrix.com'],      // must NOT match 'x.com'
        ];
    }

    /**
     * @dataProvider scrapeableOpenSources
     */
    public function test_open_source_allows_third_party_directories_and_general(string $domain): void
    {
        $this->assertTrue(WebSearchFilters::isScrapeableOpenSource($domain), "{$domain} must be scrapeable as an open source");
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function newsPortals(): array
    {
        return [
            ['delfi.lt'], ['www.delfi.lt'], ['15min.lt'], ['lrytas.lt'],
            ['lrt.lt'], ['tv3.lt'], ['ve.lt'], ['diena.lt'], ['kauno.diena.lt'],
            ['alfa.lt'], ['bns.lt'], ['respublika.lt'], ['vakaruekspresas.lt'],
            ['madeinvilnius.lt'], ['kmt.lt'],
        ];
    }

    /**
     * The whitelist now lives in the news_portals table; this is the seeded list,
     * kept here so the matcher's own behaviour is tested without a database.
     *
     * @return array<int, string>
     */
    private function portals(): array
    {
        return [
            'delfi.lt', '15min.lt', 'lrytas.lt', 'lrt.lt', 'tv3.lt', 've.lt',
            'diena.lt', 'kauno.diena.lt', 'alfa.lt', 'bns.lt', 'respublika.lt',
            'vakaruekspresas.lt', 'madeinvilnius.lt', 'kmt.lt',
        ];
    }

    /**
     * @dataProvider newsPortals
     */
    public function test_known_news_portals_are_recognised(string $domain): void
    {
        $this->assertTrue(WebSearchFilters::isNewsPortal($domain, $this->portals()), "{$domain} must be a news portal");
    }

    public function test_subdomains_of_a_whitelisted_host_match(): void
    {
        // BNS syndicates via sc.bns.lt — the `.suffix` rule must cover it.
        $this->assertTrue(WebSearchFilters::isNewsPortal('sc.bns.lt', $this->portals()));
    }

    public function test_empty_portal_list_matches_nothing(): void
    {
        // An admin disabling every portal must not accidentally allow everything.
        $this->assertFalse(WebSearchFilters::isNewsPortal('delfi.lt', []));
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function nonNewsPortals(): array
    {
        return [
            // Not on the whitelist the caller passed in.
            ['naujienos.example'], ['portalas.example'], ['www.naujienos.example'],
            // Collision + unrelated.
            ['notdelfi.lt'], ['delfi.lt.evil.com'], ['acmena.lt'], ['scoris.lt'],
        ];
    }

    /**
     * @dataProvider nonNewsPortals
     */
    public function test_non_news_portals_are_rejected(string $domain): void
    {
        $this->assertFalse(WebSearchFilters::isNewsPortal($domain, $this->portals()), "{$domain} must NOT be a news portal");
    }

    public function test_topic_and_tag_listings_are_not_articles(): void
    {
        $this->assertTrue(WebSearchFilters::isNewsListingPage('https://www.delfi.lt/temos/azerna/'));
        $this->assertTrue(WebSearchFilters::isNewsListingPage('https://www.15min.lt/tema/azerna-519'));
        $this->assertTrue(WebSearchFilters::isNewsListingPage('https://www.lrytas.lt/'));
        $this->assertFalse(WebSearchFilters::isNewsListingPage('https://www.delfi.lt/verslas/naujienos/azerna-plecia-parka-120317794'));
        // "tema" inside a slug is not a listing segment.
        $this->assertFalse(WebSearchFilters::isNewsListingPage('https://www.15min.lt/verslas/naujiena/sistema-keiciasi-12345'));
    }
}
