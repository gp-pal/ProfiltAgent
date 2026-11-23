<?php

namespace App\Services;

use DOMDocument;

/**
 * Pure (I/O-free) extractor of an article's publish date from its HTML, for the
 * news dimension when SearXNG omits publishedDate and the URL carries no date.
 * Reads, in order: <meta article:published_time>/datePublished, JSON-LD
 * datePublished (incl. @graph), then <time datetime>. Returns Y-m-d or null.
 */
class ArticleDateParser
{
    /** Meta property/name/itemprop keys that carry a publish date. */
    private const META_KEYS = ['article:published_time', 'og:article:published_time', 'datePublished'];

    /**
     * Class-name fragments of elements that render a visible publish date, for
     * portals without meta/JSON-LD dates — e.g. BNS `sc-item-date`. Lowest
     * priority (after meta/JSON-LD/<time>); kept specific to avoid false hits.
     */
    private const DATE_CLASS_HINTS = ['sc-item-date', 'article-date', 'entry-date', 'post-date', 'publish-date', 'published-date'];

    public static function fromHtml(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($previous);

        foreach ($dom->getElementsByTagName('meta') as $meta) {
            $key = $meta->getAttribute('property') ?: ($meta->getAttribute('name') ?: $meta->getAttribute('itemprop'));
            if (in_array($key, self::META_KEYS, true)) {
                $date = self::normalize($meta->getAttribute('content'));
                if ($date !== null) {
                    return $date;
                }
            }
        }

        foreach ($dom->getElementsByTagName('script') as $script) {
            if (strtolower($script->getAttribute('type')) !== 'application/ld+json') {
                continue;
            }
            $date = self::datePublishedFromLd(json_decode(trim($script->textContent), true));
            if ($date !== null) {
                return $date;
            }
        }

        foreach ($dom->getElementsByTagName('time') as $time) {
            $date = self::normalize($time->getAttribute('datetime'));
            if ($date !== null) {
                return $date;
            }
        }

        foreach (['span', 'div', 'p', 'abbr'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $element) {
                $class = strtolower($element->getAttribute('class'));
                if ($class === '') {
                    continue;
                }
                foreach (self::DATE_CLASS_HINTS as $hint) {
                    if (str_contains($class, $hint)) {
                        $date = self::normalize($element->textContent);
                        if ($date !== null) {
                            return $date;
                        }
                    }
                }
            }
        }

        return null;
    }

    private static function datePublishedFromLd(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['datePublished']) && is_string($data['datePublished'])) {
            $date = self::normalize($data['datePublished']);
            if ($date !== null) {
                return $date;
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $date = self::datePublishedFromLd($value);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        $year = (int) date('Y', $timestamp);
        if ($year < 2000 || $year > 2100) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }
}
