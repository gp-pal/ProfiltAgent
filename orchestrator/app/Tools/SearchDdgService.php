<?php

namespace App\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class SearchDdgService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            ]
        ]);
    }

    public function findOfficialSite(string $company): ?string
    {
        // Search with LT bias for Lithuanian companies
        $queries = [
            "{$company} oficiali svetainė",
            "{$company} official site",
            "{$company} homepage"
        ];

        foreach ($queries as $query) {
            $results = $this->searchWithLtBias($query);

            if ($results['ok'] && !empty($results['results'])) {
                $domain = $this->selectBestDomain($results['results'], $company);
                if ($domain) {
                    return $domain;
                }
            }
        }

        return null;
    }

    private function searchWithLtBias(string $query): array
    {
        try {
            $response = $this->client->get('https://duckduckgo.com/html/', [
                'query' => [
                    'q' => $query,
                    'kl' => 'lt-lt'  // LT bias for Lithuanian results
                ]
            ]);

            $html = $response->getBody()->getContents();
            $results = $this->parseDdgResults($html, 10);

            return [
                'ok' => true,
                'results' => $results,
                'query' => $query,
                'count' => count($results)
            ];

        } catch (RequestException $e) {
            return [
                'ok' => false,
                'error' => 'Search request failed: ' . $e->getMessage(),
                'results' => []
            ];
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'error' => 'Search parsing failed: ' . $e->getMessage(),
                'results' => []
            ];
        }
    }

    private function parseDdgResults(string $html, int $limit): array
    {
        $results = [];

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $resultNodes = $xpath->query('//div[contains(@class, "result")]');

        $count = 0;
        foreach ($resultNodes as $node) {
            if ($count >= $limit) {
                break;
            }

            $result = $this->parseResultNode($xpath, $node);
            if ($result) {
                $results[] = $result;
                $count++;
            }
        }

        return $results;
    }

    private function parseResultNode(\DOMXPath $xpath, \DOMNode $node): ?array
    {
        // Extract title and URL
        $titleNodes = $xpath->query('.//a[contains(@class, "result__a")]', $node);
        if ($titleNodes->length === 0) {
            return null;
        }

        $titleNode = $titleNodes->item(0);
        $title = trim($titleNode->textContent);
        $url = $titleNode->getAttribute('href');

        // Clean up URL (DuckDuckGo sometimes uses redirect URLs)
        if (strpos($url, '/l/?uddg=') !== false) {
            $url = urldecode(substr($url, strpos($url, 'uddg=') + 5));
        }

        // Extract snippet
        $snippetNodes = $xpath->query('.//a[contains(@class, "result__snippet")]', $node);
        $snippet = '';
        if ($snippetNodes->length > 0) {
            $snippet = trim($snippetNodes->item(0)->textContent);
        }

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return [
            'title' => $title,
            'url' => $url,
            'snippet' => $snippet,
            'domain' => parse_url($url, PHP_URL_HOST)
        ];
    }

    private function selectBestDomain(array $results, string $company): ?string
    {
        $scored = [];
        $companyLower = strtolower($company);

        foreach ($results as $result) {
            $title = strtolower($result['title']);
            $snippet = strtolower($result['snippet']);
            $domain = strtolower($result['domain']);

            // Skip directories and aggregators
            if ($this->isDirectoryOrAggregator($domain)) {
                continue;
            }

            $score = 0;

            // Company name in title (highest priority)
            if (strpos($title, $companyLower) !== false) {
                $score += 10;
            }

            // Company name in domain
            if (strpos($domain, $companyLower) !== false) {
                $score += 8;
            }

            // .lt TLD preference
            if (str_ends_with($domain, '.lt')) {
                $score += 5;
            }

            // Official indicators
            if (strpos($title, 'official') !== false ||
                strpos($snippet, 'official') !== false ||
                strpos($snippet, 'homepage') !== false ||
                strpos($title, 'oficiali') !== false) {
                $score += 5;
            }

            // Company name in snippet
            if (strpos($snippet, $companyLower) !== false) {
                $score += 3;
            }

            // Prefer shorter domains (likely more official)
            $score += max(0, 5 - substr_count($domain, '.'));

            if ($score > 0) {
                $scored[] = [
                    'domain' => $result['domain'],
                    'score' => $score,
                    'title' => $result['title'],
                    'url' => $result['url']
                ];
            }
        }

        // Sort by score
        usort($scored, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        // Return the registered domain of the best result
        if (!empty($scored)) {
            return $this->getRegisteredDomain($scored[0]['domain']);
        }

        return null;
    }

    private function isDirectoryOrAggregator(string $domain): bool
    {
        return WebSearchFilters::isDirectoryOrAggregator($domain);
    }

    private function getRegisteredDomain(string $domain): string
    {
        // Simple registered domain extraction
        $parts = explode('.', $domain);

        if (count($parts) >= 2) {
            return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
        }

        return $domain;
    }
}