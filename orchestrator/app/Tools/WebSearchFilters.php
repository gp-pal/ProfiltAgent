<?php

namespace App\Tools;

class WebSearchFilters
{
    /**
     * Domain-shaped aggregator patterns — matched by exact host OR subdomain
     * suffix (never substring), so `b-a.lt` can't false-match `web-a.lt` and
     * `facebook.com` can't false-match `notfacebook.com`.
     */
    private const HOST_PATTERNS = [
        'facebook.com', 'linkedin.com', 'twitter.com', 'x.com', 'instagram.com',
        'youtube.com', 'wikipedia.org',
        'imones.lt', '118.lt', 'aruodas.lt', 'cvmarket.lt', 'autoplius.lt',
        'scoris.lt', 'okredo.com', 'okredo.lt', 'b-a.lt', 'visalietuva.lt', 'zebra.lt',
    ];

    /**
     * Distinctive keyword fragments — matched anywhere in the host. Only words
     * that never legitimately appear in a real company's own domain.
     */
    private const KEYWORD_PATTERNS = [
        'yellowpages', 'directory', 'listing', 'bizdir', 'companies',
        'rekvizitai', 'visasverslas', 'cvbankas', 'imoniukatalogas', 'manoimone',
        'kreditoreitingas', 'creditinfo', 'okredito',
    ];

    /**
     * Directories, aggregators and social networks that are never a company's
     * own official website.
     */
    public static function isDirectoryOrAggregator(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }

        foreach (self::HOST_PATTERNS as $host) {
            if ($domain === $host || str_ends_with($domain, '.' . $host)) {
                return true;
            }
        }

        foreach (self::KEYWORD_PATTERNS as $keyword) {
            if (str_contains($domain, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hosts never worth scraping as an OPEN informational source. This is a much
     * smaller list than the domain-resolution blocklist: a third-party directory
     * is not a company's own site (so isDirectoryOrAggregator rejects it), yet it
     * still carries real data about the company and IS a valid open source.
     * Blocked here are only:
     *   - the state register's own site (a registry DB source is the way to
     *     that data, not a scrape of its portal);
     *   - directories that refuse automated readers with a bot challenge — a
     *     fetch returns no content and only wastes one of the few page slots;
     *   - login-walled / ToS social networks (unscrapeable);
     *   - product classifieds (car/real-estate ads — noise, not company data).
     */
    private const OPEN_SOURCE_BLOCK_HOSTS = [
        'registrucentras.lt', 'rc.lt',
        'okredo.com', 'okredo.lt', 'scoris.lt', 'rekvizitai.lt',
        'facebook.com', 'linkedin.com', 'instagram.com', 'x.com', 'twitter.com', 'youtube.com',
        'aruodas.lt', 'autoplius.lt',
    ];

    /**
     * Whether a domain is worth scraping as an OPEN informational source about a
     * company (universal collector). Unlike isDirectoryOrAggregator (used for
     * official-site resolution, where every directory is rejected), third-party
     * business/credit directories and job boards ARE allowed here — they carry
     * contacts and descriptions, which is often the only trace of a firm with no
     * official site. Only the state register, login-walled social networks and
     * product classifieds are skipped.
     */
    public static function isScrapeableOpenSource(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }

        foreach (self::OPEN_SOURCE_BLOCK_HOSTS as $host) {
            if ($domain === $host || str_ends_with($domain, '.' . $host)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a URL is a portal's topic/tag/search listing rather than an article.
     * Such a page names the company in its title and so passes the on-topic gate,
     * but it is an index of articles with no publish date of its own.
     */
    public static function isNewsListingPage(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (trim($path, '/') === '') {
            return true; // the portal's front page
        }

        return preg_match('#/(tema|temos|tag|tags|zyme|zymes|raktazodis|raktazodziai|topic|topics|paieska|search)(/|$)#', $path) === 1;
    }

    /**
     * Whether a domain belongs to the curated news whitelist. Matched by exact
     * host OR `.suffix` (never substring), so `bns.lt` also covers `sc.bns.lt`.
     *
     * The list is supplied by the caller rather than hardcoded here: it lives in
     * the news_portals table so an admin can change it without a deploy (see
     * {@see \App\Services\NewsPortalRegistry}). A deployment that publishes news
     * itself should leave its own portal off that list, so its own archive is never
     * counted as a third-party mention.
     *
     * @param array<int, string> $portals
     */
    public static function isNewsPortal(string $domain, array $portals): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }

        foreach ($portals as $host) {
            $host = strtolower(trim($host));
            if ($host !== '' && ($domain === $host || str_ends_with($domain, '.' . $host))) {
                return true;
            }
        }

        return false;
    }
}
