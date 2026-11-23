<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Offline Link Extractor - Deterministic link processing
 *
 * Creates normalized, categorized link inventory from persisted HTML assets.
 * Produces links.json, optional links.md, and links_summary for controller.
 */
class LinkExtractor
{
    private array $trackingParams = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'msclkid', '_ga', '_gl', 'mc_eid'
    ];

    private array $aboutHints = [
        'en' => ['about', 'company', 'who we are', 'our story', 'team', 'history'],
        'lt' => ['apie', 'kompanija', 'kas mes', 'istorija', 'komanda', 'apie mus']
    ];

    private array $navHints = [
        'header', 'nav', 'navigation', 'menu', 'footer', 'sitemap', 'breadcrumb'
    ];

    /**
     * Process links from HTML assets and raw links data
     */
    public function extractAndProcess(string $basePath, string $domain): array
    {
        try {
            // Read HTML files
            $htmlFiles = $this->readHtmlFiles($basePath);

            // Read links_raw.json if exists
            $rawLinks = $this->readRawLinks($basePath);

            // Combine and process all links
            $allLinks = $this->combineLinks($htmlFiles, $rawLinks, $domain);

            // Process and categorize
            $processedLinks = $this->processLinks($allLinks, $domain);

            // Generate summary
            $summary = $this->generateSummary($processedLinks);

            // Write outputs
            $this->writeLinksJson($basePath, $processedLinks);
            $this->writeLinksMarkdown($basePath, $processedLinks);

            return [
                'links' => $processedLinks,
                'summary' => $summary
            ];

        } catch (\Throwable $e) {
            Log::error('Link extraction failed', [
                'base_path' => $basePath,
                'domain' => $domain,
                'error' => $e->getMessage()
            ]);

            return [
                'links' => [],
                'summary' => $this->getEmptySummary()
            ];
        }
    }

    /**
     * Read all HTML files from the directory
     */
    private function readHtmlFiles(string $basePath): array
    {
        $htmlFiles = [];

        // Read main.html
        $mainPath = storage_path("{$basePath}/main.html");
        if (file_exists($mainPath)) {
            $htmlFiles['main'] = file_get_contents($mainPath);
        }

        // Read iframe files
        $iframesDir = storage_path("{$basePath}/iframes");
        if (is_dir($iframesDir)) {
            $iframeFiles = glob("{$iframesDir}/*.html");
            foreach ($iframeFiles as $iframeFile) {
                $filename = basename($iframeFile, '.html');
                $htmlFiles[$filename] = file_get_contents($iframeFile);
            }
        }

        return $htmlFiles;
    }

    /**
     * Read links_raw.json from runner output
     */
    private function readRawLinks(string $basePath): array
    {
        $rawLinksPath = storage_path("{$basePath}/links_raw.json");
        if (!file_exists($rawLinksPath)) {
            return [];
        }

        $content = file_get_contents($rawLinksPath);
        $data = json_decode($content, true);

        return $data ?? [];
    }

    /**
     * Combine links from HTML parsing and raw runner data
     */
    private function combineLinks(array $htmlFiles, array $rawLinks, string $domain): array
    {
        $allLinks = [];

        // Extract from HTML files
        foreach ($htmlFiles as $source => $html) {
            $htmlLinks = $this->extractLinksFromHtml($html, $source, $domain);
            $allLinks = array_merge($allLinks, $htmlLinks);
        }

        // Add raw links from runner
        foreach ($rawLinks as $rawLink) {
            if (isset($rawLink['href']) && isset($rawLink['text'])) {
                $allLinks[] = [
                    'href_raw' => $rawLink['href'],
                    'text' => trim($rawLink['text']),
                    'title' => $rawLink['title'] ?? '',
                    'location' => 'runner_extract'
                ];
            }
        }

        return $allLinks;
    }

    /**
     * Extract links from HTML content
     */
    private function extractLinksFromHtml(string $html, string $source, string $domain): array
    {
        $links = [];

        try {
            $crawler = new Crawler($html);
            $baseUrl = "https://{$domain}";

            $crawler->filter('a[href]')->each(function (Crawler $link) use (&$links, $source, $baseUrl) {
                $href = $link->attr('href');
                $text = trim($link->text());
                $title = trim($link->attr('title') ?? '');

                if (empty($href)) {
                    return;
                }

                // Determine location context
                $location = $this->determineLocation($link, $source);

                $links[] = [
                    'href_raw' => $href,
                    'text' => $text,
                    'title' => $title,
                    'location' => $location
                ];
            });

        } catch (\Exception $e) {
            Log::warning('HTML link extraction failed', [
                'source' => $source,
                'error' => $e->getMessage()
            ]);
        }

        return $links;
    }

    /**
     * Determine link location context (header, nav, footer, etc.)
     */
    private function determineLocation(Crawler $link, string $source): string
    {
        try {
            $parents = [];
            $current = $link;

            // Traverse up to collect parent elements
            for ($i = 0; $i < 5; $i++) {
                $current = $current->ancestors()->first();
                if ($current->count() === 0) break;

                $tagName = strtolower($current->nodeName());
                $className = strtolower($current->attr('class') ?? '');
                $id = strtolower($current->attr('id') ?? '');

                $parents[] = "{$tagName} {$className} {$id}";
            }

            $context = implode(' ', $parents);

            // Check for navigation context
            foreach ($this->navHints as $hint) {
                if (strpos($context, $hint) !== false) {
                    return "nav_{$hint}";
                }
            }

            return $source;

        } catch (\Exception $e) {
            return $source;
        }
    }

    /**
     * Process and categorize links
     */
    private function processLinks(array $allLinks, string $domain): array
    {
        $processed = [];
        $seen = [];

        foreach ($allLinks as $link) {
            // Resolve URL
            $hrefAbs = $this->resolveUrl($link['href_raw'], $domain);

            // Clean tracking parameters
            $hrefClean = $this->cleanTrackingParams($hrefAbs);

            // Create unique key for deduplication
            $uniqueKey = $hrefClean . '|' . $link['location'];
            if (isset($seen[$uniqueKey])) {
                continue;
            }
            $seen[$uniqueKey] = true;

            // Categorize
            $category = $this->categorizeLink($hrefClean, $link['text'], $domain);

            // Add hints
            $hints = $this->generateHints($link['text'], $link['href_raw'], $link['title']);

            $processed[] = [
                'href_raw' => $link['href_raw'],
                'href_abs' => $hrefAbs,
                'href_clean' => $hrefClean,
                'text' => $link['text'],
                'title' => $link['title'],
                'location' => $link['location'],
                'category' => $category,
                'about_hint' => $hints['about_hint'],
                'nav_hint' => $hints['nav_hint']
            ];
        }

        // Sort by category, then by text
        usort($processed, function($a, $b) {
            if ($a['category'] !== $b['category']) {
                return strcmp($a['category'], $b['category']);
            }
            return strcmp($a['text'], $b['text']);
        });

        // Limit total links (policy constraint)
        return array_slice($processed, 0, 1000);
    }

    /**
     * Resolve relative URLs to absolute URLs
     */
    private function resolveUrl(string $href, string $domain): string
    {
        // Already absolute URL
        if (preg_match('/^https?:\/\//', $href)) {
            return $href;
        }

        // Protocol-relative
        if (strpos($href, '//') === 0) {
            return "https:{$href}";
        }

        // Absolute path
        if (strpos($href, '/') === 0) {
            return "https://{$domain}{$href}";
        }

        // Relative path
        return "https://{$domain}/{$href}";
    }

    /**
     * Clean tracking parameters from URLs
     */
    private function cleanTrackingParams(string $url): string
    {
        $parsed = parse_url($url);
        if (!isset($parsed['query'])) {
            return $url;
        }

        parse_str($parsed['query'], $params);

        // Remove tracking parameters
        foreach ($this->trackingParams as $trackingParam) {
            unset($params[$trackingParam]);
        }

        // Rebuild URL
        $cleanUrl = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $cleanUrl .= ':' . $parsed['port'];
        }
        if (isset($parsed['path'])) {
            $cleanUrl .= $parsed['path'];
        }
        if (!empty($params)) {
            $cleanUrl .= '?' . http_build_query($params);
        }
        if (isset($parsed['fragment'])) {
            $cleanUrl .= '#' . $parsed['fragment'];
        }

        return $cleanUrl;
    }

    /**
     * Categorize link based on URL and content
     */
    private function categorizeLink(string $url, string $text, string $domain): string
    {
        $urlLower = strtolower($url);
        $textLower = strtolower($text);
        $parsedUrl = parse_url($url);
        $urlDomain = $parsedUrl['host'] ?? '';

        // Email links
        if (strpos($url, 'mailto:') === 0) {
            return 'mailto';
        }

        // Phone links
        if (strpos($url, 'tel:') === 0) {
            return 'tel';
        }

        // Anchor links
        if (strpos($url, '#') === 0) {
            return 'anchor';
        }

        // File downloads
        if (preg_match('/\.(pdf|doc|docx|xls|xlsx|zip|rar|tar|gz)$/i', $urlLower)) {
            return 'file';
        }

        // External vs internal
        if ($urlDomain !== $domain && !empty($urlDomain)) {
            return 'external';
        }

        return 'internal';
    }

    /**
     * Generate hints for link classification
     */
    private function generateHints(string $text, string $href, string $title): array
    {
        $textLower = strtolower($text);
        $hrefLower = strtolower($href);
        $titleLower = strtolower($title);
        $combined = "{$textLower} {$hrefLower} {$titleLower}";

        $hints = [
            'about_hint' => false,
            'nav_hint' => false
        ];

        // About hint
        foreach ($this->aboutHints as $lang => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($combined, $pattern) !== false) {
                    $hints['about_hint'] = true;
                    break 2;
                }
            }
        }

        // Navigation hint
        foreach ($this->navHints as $hint) {
            if (strpos($combined, $hint) !== false) {
                $hints['nav_hint'] = true;
                break;
            }
        }

        return $hints;
    }

    /**
     * Generate summary statistics
     */
    private function generateSummary(array $links): array
    {
        $totalLinks = count($links);
        $uniqueLinks = count(array_unique(array_column($links, 'href_clean')));
        $byCategory = [];
        $domains = [];

        foreach ($links as $link) {
            // Count by category
            $category = $link['category'];
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;

            // Collect domains
            $parsedUrl = parse_url($link['href_abs']);
            if (isset($parsedUrl['host'])) {
                $domains[$parsedUrl['host']] = true;
            }
        }

        return [
            'total_links' => $totalLinks,
            'unique_links' => $uniqueLinks,
            'by_category' => $byCategory,
            'unique_domains' => count($domains)
        ];
    }

    /**
     * Write links.json file
     */
    private function writeLinksJson(string $basePath, array $links): void
    {
        $jsonPath = storage_path($basePath) . "/links.json";

        // Ensure the directory structure exists before writing the file
        $directory = dirname($jsonPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }

        $json = json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($jsonPath, $json);
    }

    /**
     * Write optional links.md file
     */
    private function writeLinksMarkdown(string $basePath, array $links): void
    {
        $mdPath = storage_path($basePath) . "/links.md";

        // Ensure the directory structure exists before writing the file
        $directory = dirname($mdPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }

        $markdown = "# Link Inventory\n\n";
        $markdown .= "Total links: " . count($links) . "\n\n";

        // Group by category
        $byCategory = [];
        foreach ($links as $link) {
            $byCategory[$link['category']][] = $link;
        }

        foreach ($byCategory as $category => $categoryLinks) {
            $markdown .= "## " . ucfirst($category) . " Links (" . count($categoryLinks) . ")\n\n";

            foreach ($categoryLinks as $link) {
                $text = !empty($link['text']) ? $link['text'] : '[No text]';
                $markdown .= "- [{$text}]({$link['href_abs']})";

                if ($link['about_hint']) {
                    $markdown .= " 🏢";
                }
                if ($link['nav_hint']) {
                    $markdown .= " 🧭";
                }

                $markdown .= "\n";
            }

            $markdown .= "\n";
        }

        file_put_contents($mdPath, $markdown);
    }

    /**
     * Get empty summary for error cases
     */
    private function getEmptySummary(): array
    {
        return [
            'total_links' => 0,
            'unique_links' => 0,
            'by_category' => [],
            'unique_domains' => 0
        ];
    }
}