<?php

namespace Tests\Unit;

use App\Services\ArticleDateParser;
use Tests\TestCase;

class ArticleDateParserTest extends TestCase
{
    public function test_reads_article_published_time_meta_either_attribute_order(): void
    {
        $a = '<html><head><meta property="article:published_time" content="2026-05-01T10:00:00+03:00"></head></html>';
        $b = '<html><head><meta content="2026-05-01T10:00:00+03:00" property="article:published_time"></head></html>';

        $this->assertSame('2026-05-01', ArticleDateParser::fromHtml($a));
        $this->assertSame('2026-05-01', ArticleDateParser::fromHtml($b));
    }

    public function test_reads_json_ld_date_published_including_graph(): void
    {
        $plain = '<html><head><script type="application/ld+json">{"@type":"NewsArticle","datePublished":"2026-04-10T08:30:00Z"}</script></head></html>';
        $graph = '<html><head><script type="application/ld+json">{"@graph":[{"@type":"WebSite"},{"@type":"NewsArticle","datePublished":"2026-03-15"}]}</script></head></html>';

        $this->assertSame('2026-04-10', ArticleDateParser::fromHtml($plain));
        $this->assertSame('2026-03-15', ArticleDateParser::fromHtml($graph));
    }

    public function test_reads_time_datetime_attribute(): void
    {
        $html = '<html><body><article><time datetime="2026-02-20T12:00:00">vasario 20</time></article></body></html>';

        $this->assertSame('2026-02-20', ArticleDateParser::fromHtml($html));
    }

    public function test_returns_null_when_no_date_or_empty(): void
    {
        $this->assertNull(ArticleDateParser::fromHtml('<html><head><title>Acmena</title></head></html>'));
        $this->assertNull(ArticleDateParser::fromHtml(''));
        $this->assertNull(ArticleDateParser::fromHtml(null));
    }

    public function test_reads_date_from_bns_item_date_span(): void
    {
        // BNS (sc.bns.lt) carries no meta/JSON-LD date — the date lives in a
        // <span class="sc-item-date">YYYY-MM-DD HH:MM</span>.
        $html = '<html><body><article><span class="sc-item-date">2020-01-29 12:11</span></article></body></html>';

        $this->assertSame('2020-01-29', ArticleDateParser::fromHtml($html));
    }

    public function test_meta_wins_over_date_class_span(): void
    {
        // A real meta date takes priority over a class-based span heuristic.
        $html = '<html><head><meta property="article:published_time" content="2026-05-01"></head>'
            . '<body><span class="sc-item-date">2020-01-29 12:11</span></body></html>';

        $this->assertSame('2026-05-01', ArticleDateParser::fromHtml($html));
    }

    public function test_rejects_implausible_year(): void
    {
        $html = '<html><head><meta property="article:published_time" content="1755-01-01"></head></html>';

        $this->assertNull(ArticleDateParser::fromHtml($html));
    }
}
