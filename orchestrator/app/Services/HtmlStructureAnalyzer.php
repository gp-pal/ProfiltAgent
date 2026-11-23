<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * HTML Structure Analyzer for intelligent website navigation and content discovery
 *
 * Analyzes HTML structure to identify important links, navigation patterns,
 * and content areas for enhanced ReAct loop decision making.
 */
class HtmlStructureAnalyzer
{
    private array $languagePatterns = [
        'en' => [
            'about' => ['about', 'about us', 'company', 'who we are', 'our story', 'team'],
            'products' => ['products', 'services', 'solutions', 'what we do', 'portfolio'],
            'contact' => ['contact', 'contact us', 'get in touch', 'reach us'],
            'news' => ['news', 'blog', 'updates', 'press', 'media'],
            'careers' => ['careers', 'jobs', 'work with us', 'join us', 'hiring']
        ],
        'lt' => [
            'about' => ['apie', 'apie mus', 'apie įmonę', 'kompanija', 'kas mes', 'istorija', 'mūsų komanda'],
            'products' => ['produktai', 'paslaugos', 'sprendimai', 'ką darome', 'portfelis'],
            'contact' => ['kontaktai', 'susisiekite', 'kreipkitės', 'ryšys'],
            'news' => ['naujienos', 'blog', 'aktualijos', 'spauda', 'žiniasklaida'],
            'careers' => ['karjera', 'darbas', 'prisijunkite', 'komanda', 'darbuotojai']
        ]
    ];

    /**
     * Analyze HTML content and extract structured navigation information
     */
    public function analyzeStructure(string $html, string $baseUrl = ''): array
    {
        try {
            // Strip script/style tags before analysis to prevent SDK keys and inline content from polluting extraction
            $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
            $crawler = new Crawler($html);

            return [
                'navigation' => $this->extractNavigation($crawler, $baseUrl),
                'important_links' => $this->findImportantLinks($crawler, $baseUrl),
                'content_areas' => $this->identifyContentAreas($crawler),
                'language' => $this->detectLanguage($html),
                'page_type' => $this->detectPageType($html),
                'company_indicators' => $this->findCompanyIndicators($crawler),
                'structure_confidence' => $this->calculateStructureConfidence($crawler)
            ];

        } catch (\Exception $e) {
            Log::error('HTML structure analysis failed', [
                'error' => $e->getMessage(),
                'html_length' => strlen($html)
            ]);

            return $this->getEmptyStructure();
        }
    }

    /**
     * Extract main navigation elements
     */
    private function extractNavigation(Crawler $crawler, string $baseUrl): array
    {
        $navigation = [
            'main_nav' => [],
            'header_nav' => [],
            'footer_nav' => [],
            'breadcrumbs' => []
        ];

        // Main navigation selectors
        $mainNavSelectors = [
            'nav',
            '.nav',
            '.navigation',
            '.main-nav',
            '.primary-nav',
            'header nav',
            'header ul',
            '.menu',
            '.navbar'
        ];

        foreach ($mainNavSelectors as $selector) {
            try {
                $navElements = $crawler->filter($selector);
                if ($navElements->count() > 0) {
                    $links = $this->extractLinksFromElement($navElements, $baseUrl);
                    if (!empty($links)) {
                        $navigation['main_nav'] = array_merge($navigation['main_nav'], $links);
                    }
                }
            } catch (\Exception $e) {
                // Continue with other selectors
            }
        }

        // Header navigation
        try {
            $headerNav = $crawler->filter('header a, .header a, .top-nav a');
            $navigation['header_nav'] = $this->extractLinksFromElement($headerNav, $baseUrl);
        } catch (\Exception $e) {
            // Header nav not found
        }

        // Footer navigation
        try {
            $footerNav = $crawler->filter('footer a, .footer a');
            $navigation['footer_nav'] = $this->extractLinksFromElement($footerNav, $baseUrl);
        } catch (\Exception $e) {
            // Footer nav not found
        }

        // Remove duplicates
        $navigation['main_nav'] = $this->removeDuplicateLinks($navigation['main_nav']);
        $navigation['header_nav'] = $this->removeDuplicateLinks($navigation['header_nav']);
        $navigation['footer_nav'] = $this->removeDuplicateLinks($navigation['footer_nav']);

        return $navigation;
    }

    /**
     * Find important links based on content and patterns
     */
    private function findImportantLinks(Crawler $crawler, string $baseUrl): array
    {
        $importantLinks = [
            'about' => [],
            'products' => [],
            'contact' => [],
            'news' => [],
            'careers' => []
        ];

        $language = $this->detectLanguage($crawler->html());
        $patterns = $this->languagePatterns[$language] ?? $this->languagePatterns['en'];

        try {
            $allLinks = $crawler->filter('a[href]');

            $allLinks->each(function (Crawler $link) use (&$importantLinks, $patterns, $baseUrl) {
                $href = $link->attr('href');
                $text = strtolower(trim($link->text()));
                $title = strtolower(trim($link->attr('title') ?? ''));

                if (empty($href) || empty($text)) {
                    return;
                }

                $fullUrl = $this->resolveUrl($href, $baseUrl);

                $linkData = [
                    'text' => $text,
                    'url' => $fullUrl,
                    'href' => $href,
                    'title' => $title
                ];

                // Categorize link based on text and patterns
                foreach ($importantLinks as $category => &$categoryLinks) {
                    $categoryPatterns = $patterns[$category] ?? [];

                    foreach ($categoryPatterns as $pattern) {
                        if (strpos($text, $pattern) !== false ||
                            strpos($title, $pattern) !== false ||
                            strpos($href, $pattern) !== false) {
                            $categoryLinks[] = $linkData;
                            break 2; // Break both loops
                        }
                    }
                }
            });

        } catch (\Exception $e) {
            Log::warning('Failed to extract important links', ['error' => $e->getMessage()]);
        }

        // Remove duplicates and limit results
        foreach ($importantLinks as $category => $links) {
            $importantLinks[$category] = array_slice($this->removeDuplicateLinks($links), 0, 5);
        }

        return $importantLinks;
    }

    /**
     * Identify main content areas
     */
    private function identifyContentAreas(Crawler $crawler): array
    {
        $contentAreas = [
            'main_content' => null,
            'sidebar' => null,
            'hero_section' => null,
            'features' => [],
            'testimonials' => [],
            'team' => []
        ];

        try {
            // Main content area
            $mainSelectors = ['main', '.main', '#main', '.content', '#content', '.main-content'];
            foreach ($mainSelectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $contentAreas['main_content'] = [
                        'selector' => $selector,
                        'text_length' => strlen($element->text()),
                        'has_headings' => $element->filter('h1, h2, h3')->count() > 0
                    ];
                    break;
                }
            }

            // Hero section
            $heroSelectors = ['.hero', '.banner', '.jumbotron', '.intro', '.header-content'];
            foreach ($heroSelectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $contentAreas['hero_section'] = [
                        'selector' => $selector,
                        'text' => trim(substr($element->text(), 0, 200))
                    ];
                    break;
                }
            }

            // Team sections (important for Lithuanian companies)
            $teamSelectors = ['.team', '.staff', '.people', '.komanda', '.darbuotojai'];
            foreach ($teamSelectors as $selector) {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    $contentAreas['team'][] = [
                        'selector' => $selector,
                        'count' => $elements->count(),
                        'text_preview' => trim(substr($elements->first()->text(), 0, 100))
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::warning('Failed to identify content areas', ['error' => $e->getMessage()]);
        }

        return $contentAreas;
    }

    /**
     * Detect page language (Lithuanian vs English)
     */
    private function detectLanguage(string $html): string
    {
        $ltWords = ['apie', 'mus', 'kompanija', 'paslaugos', 'kontaktai', 'įmonė', 'darbuotojai', 'komanda'];
        $enWords = ['about', 'company', 'services', 'contact', 'team', 'products', 'solutions'];

        $htmlLower = strtolower($html);

        $ltCount = 0;
        $enCount = 0;

        foreach ($ltWords as $word) {
            $ltCount += substr_count($htmlLower, $word);
        }

        foreach ($enWords as $word) {
            $enCount += substr_count($htmlLower, $word);
        }

        return $ltCount > $enCount ? 'lt' : 'en';
    }

    /**
     * Detect page type (homepage, about, products, etc.)
     */
    private function detectPageType(string $html): string
    {
        $htmlLower = strtolower($html);

        // Check for about page indicators
        $aboutIndicators = ['about us', 'about the company', 'apie mus', 'apie įmonę'];
        foreach ($aboutIndicators as $indicator) {
            if (strpos($htmlLower, $indicator) !== false) {
                return 'about';
            }
        }

        // Check for contact page indicators
        $contactIndicators = ['contact us', 'get in touch', 'kontaktai', 'susisiekite'];
        foreach ($contactIndicators as $indicator) {
            if (strpos($htmlLower, $indicator) !== false) {
                return 'contact';
            }
        }

        // Check for products/services page
        $productIndicators = ['our products', 'our services', 'mūsų produktai', 'mūsų paslaugos'];
        foreach ($productIndicators as $indicator) {
            if (strpos($htmlLower, $indicator) !== false) {
                return 'products';
            }
        }

        // Default to homepage
        return 'homepage';
    }

    /**
     * Find company-specific indicators in the content
     */
    private function findCompanyIndicators(Crawler $crawler): array
    {
        $indicators = [
            'company_name_mentions' => 0,
            'business_info' => [],
            'contact_info' => [],
            'social_links' => []
        ];

        try {
            $bodyText = $crawler->filter('body')->text();

            // Find email addresses
            preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $bodyText, $emails);
            $indicators['contact_info']['emails'] = array_values(array_filter(
                array_unique($emails[0] ?? []),
                static fn (string $email): bool => ! EmailSanitizer::looksLikeFilename($email),
            ));

            // Find phone numbers: tel: links are authoritative; the text regex is
            // limited to Lithuanian formats (+370 / national 8 prefix, 8 digits after
            // the prefix) so timestamps and numeric IDs are not matched.
            $phones = [];
            $crawler->filter('a[href^="tel:"]')->each(function (Crawler $link) use (&$phones) {
                $phone = trim(rawurldecode(substr((string) $link->attr('href'), 4)));
                if ($phone !== '') {
                    $phones[] = $phone;
                }
            });
            preg_match_all('/(?<!\d)(?:\+370|8)(?:[\s\-.]?\d){8}(?!\d)/u', $bodyText, $matches);
            $phones = array_merge($phones, $matches[0] ?? []);
            $indicators['contact_info']['phones'] = array_values(array_unique(array_map('trim', $phones)));

            // Find social media links: match by host (not substring) and skip
            // policy/share/widget paths so only company profile URLs remain.
            $socialDomains = ['facebook.com', 'linkedin.com', 'twitter.com', 'instagram.com'];
            $blockedPathParts = [
                'policy', 'privacy', 'legal', 'terms', 'cookies',
                'sharer', 'share.php', 'intent/', '/plugins/', '/tr/',
                'help/', 'support/',
            ];
            $crawler->filter('a[href]')->each(function (Crawler $link) use (&$indicators, $socialDomains, $blockedPathParts) {
                $href = trim((string) $link->attr('href'));
                $host = strtolower((string) parse_url($href, PHP_URL_HOST));
                $path = strtolower((string) parse_url($href, PHP_URL_PATH));

                foreach ($socialDomains as $domain) {
                    if ($host !== $domain && !str_ends_with($host, ".{$domain}")) {
                        continue;
                    }
                    foreach ($blockedPathParts as $blocked) {
                        if (str_contains($path, $blocked)) {
                            return;
                        }
                    }
                    $indicators['social_links'][] = rtrim($href, '/');
                    return;
                }
            });
            $indicators['social_links'] = array_values(array_unique($indicators['social_links']));

        } catch (\Exception $e) {
            Log::warning('Failed to find company indicators', ['error' => $e->getMessage()]);
        }

        return $indicators;
    }

    /**
     * Calculate confidence score for structure analysis
     */
    private function calculateStructureConfidence(Crawler $crawler): float
    {
        $confidence = 0.0;

        try {
            // Check for basic HTML structure elements
            if ($crawler->filter('nav, .nav, .navigation')->count() > 0) {
                $confidence += 0.3;
            }

            if ($crawler->filter('header, .header')->count() > 0) {
                $confidence += 0.2;
            }

            if ($crawler->filter('main, .main, .content')->count() > 0) {
                $confidence += 0.2;
            }

            if ($crawler->filter('footer, .footer')->count() > 0) {
                $confidence += 0.1;
            }

            // Check for meaningful content
            $textLength = strlen($crawler->filter('body')->text());
            if ($textLength > 500) {
                $confidence += 0.1;
            }

            if ($textLength > 2000) {
                $confidence += 0.1;
            }

        } catch (\Exception $e) {
            $confidence = 0.3; // Default low confidence
        }

        return min(1.0, $confidence);
    }

    /**
     * Extract links from a Crawler element
     */
    private function extractLinksFromElement(Crawler $element, string $baseUrl): array
    {
        $links = [];

        try {
            $element->filter('a[href]')->each(function (Crawler $link) use (&$links, $baseUrl) {
                $href = $link->attr('href');
                $text = trim($link->text());

                if (!empty($href) && !empty($text)) {
                    $links[] = [
                        'text' => $text,
                        'url' => $this->resolveUrl($href, $baseUrl),
                        'href' => $href
                    ];
                }
            });
        } catch (\Exception $e) {
            // Return empty array on error
        }

        return $links;
    }

    /**
     * Resolve relative URLs to absolute URLs
     */
    private function resolveUrl(string $href, string $baseUrl): string
    {
        if (empty($baseUrl) || filter_var($href, FILTER_VALIDATE_URL)) {
            return $href;
        }

        $baseUrl = rtrim($baseUrl, '/');

        if (strpos($href, '/') === 0) {
            // Absolute path
            $parsedBase = parse_url($baseUrl);
            return $parsedBase['scheme'] . '://' . $parsedBase['host'] . $href;
        } else {
            // Relative path
            return $baseUrl . '/' . ltrim($href, '/');
        }
    }

    /**
     * Remove duplicate links based on URL
     */
    private function removeDuplicateLinks(array $links): array
    {
        $seen = [];
        $unique = [];

        foreach ($links as $link) {
            $url = $link['url'] ?? $link['href'] ?? '';
            if (!empty($url) && !isset($seen[$url])) {
                $seen[$url] = true;
                $unique[] = $link;
            }
        }

        return $unique;
    }

    /**
     * Get empty structure for error cases
     */
    private function getEmptyStructure(): array
    {
        return [
            'navigation' => ['main_nav' => [], 'header_nav' => [], 'footer_nav' => [], 'breadcrumbs' => []],
            'important_links' => ['about' => [], 'products' => [], 'contact' => [], 'news' => [], 'careers' => []],
            'content_areas' => ['main_content' => null, 'sidebar' => null, 'hero_section' => null, 'features' => [], 'testimonials' => [], 'team' => []],
            'language' => 'en',
            'page_type' => 'unknown',
            'company_indicators' => ['company_name_mentions' => 0, 'business_info' => [], 'contact_info' => [], 'social_links' => []],
            'structure_confidence' => 0.0
        ];
    }
}