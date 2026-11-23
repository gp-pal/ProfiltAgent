<?php

namespace App\Services;

use App\IO\RunnerClient;
use App\Tools\SearxngSearchService;
use App\Tools\WebSearchFilters;
use Illuminate\Support\Facades\Log;

/**
 * Contact-focused source: runs contact-oriented searches, fetches open pages that
 * mention the target, and extracts emails / Lithuanian phone numbers by regex
 * (deterministic — more reliable than an LLM for literal contacts). Fills the
 * email/phone gap for site-less firms whose contacts aren't in the registry.
 * Only pages that actually name the company are mined, and the collector still
 * match-gates the result against the target via the judge.
 */
class ContactsSource implements CollectionSource
{
    private const MAX_PER_FIELD = 5;
    private const CONTENT_SNIPPET = 4000;

    /** Emails at these hosts / with these local parts are boilerplate, not the target's. */
    private const EMAIL_NOISE = ['example.com', 'sentry.io', 'wixpress.com', 'w3.org', 'schema.org', 'domain.com', 'email.com'];

    public function __construct(
        private SearxngSearchService $search,
        private RunnerClient $runner,
    ) {
    }

    public function name(): string
    {
        return 'contacts';
    }

    public function supports(array $target): bool
    {
        return trim((string) ($target['name'] ?? '')) !== '' && (bool) config('react.contacts.enabled', true);
    }

    public function collect(array $target): CollectionResult
    {
        if (!$this->supports($target)) {
            return CollectionResult::none($this->name(), 'skipped');
        }

        $name = (string) $target['name'];
        $domains = array_values(array_filter(array_map(
            static fn ($d): string => strtolower(trim((string) $d)),
            (array) ($target['verified_domains'] ?? []),
        )));
        $urls = $this->discoverUrls($name);
        if ($urls === []) {
            return CollectionResult::none($this->name(), 'empty', ['detail' => 'no contact pages found']);
        }

        $emails = [];
        $phones = [];
        $pagesMined = [];
        $snippet = '';

        foreach ($urls as $url) {
            $content = $this->fetch($url);
            // Only mine pages that actually reference the target company.
            if ($content === '' || mb_stripos($content, $name) === false) {
                continue;
            }

            $pagesMined[] = $url;
            if ($snippet === '') {
                $snippet = mb_substr($content, 0, self::CONTENT_SNIPPET);
            }
            foreach ($this->extractEmails($content, $name, $domains) as $email) {
                $emails[$email] = true;
            }
            foreach ($this->extractPhones($content) as $phone) {
                $phones[$phone] = true;
            }
        }

        $fields = [];
        if ($emails !== []) {
            $fields['emails'] = array_slice(array_keys($emails), 0, self::MAX_PER_FIELD);
        }
        if ($phones !== []) {
            $fields['phones'] = array_slice(array_keys($phones), 0, self::MAX_PER_FIELD);
        }

        return new CollectionResult(
            $this->name(),
            $fields === [] ? 'empty' : 'ok',
            $fields,
            ['pages' => $pagesMined, 'content' => $snippet],
        );
    }

    /**
     * @return array<int, string>
     */
    private function discoverUrls(string $name): array
    {
        $max = (int) config('react.contacts.max_pages', 4);
        $urls = [];

        foreach (["{$name} kontaktai", "{$name} el. paštas telefonas"] as $query) {
            try {
                $this->search->searchCandidates($query, 8);
            } catch (\Throwable $e) {
                Log::warning('ContactsSource search failed', ['query' => $query, 'error' => $e->getMessage()]);
                continue;
            }

            foreach ($this->search->lastResults() as $r) {
                $url = (string) ($r['url'] ?? '');
                $domain = (string) ($r['domain'] ?? '');
                if ($url !== '' && !in_array($url, $urls, true) && WebSearchFilters::isScrapeableOpenSource($domain)) {
                    $urls[] = $url;
                }
                if (count($urls) >= $max) {
                    break 2;
                }
            }
        }

        return $urls;
    }

    private function fetch(string $url): string
    {
        try {
            $res = $this->runner->run([
                'version' => '1.0',
                'meta' => ['task' => 'contacts fetch', 'company' => 'contacts', 'domain' => (string) parse_url($url, PHP_URL_HOST)],
                'actions' => [
                    ['do' => 'goto', 'url' => $url, 'timeout_ms' => 20000],
                    ['do' => 'extract_markdown', 'save_as' => 'content'],
                    ['do' => 'return', 'data' => ['content']],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ContactsSource fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return '';
        }

        return empty($res['ok']) ? '' : trim((string) ($res['data']['content'] ?? ''));
    }

    /**
     * Emails whose host is tied to the target — so a directory/service address that
     * merely appears on a page mentioning the company (e.g. pagalba@katalogas.example
     * on a yellow-pages listing) is NOT taken as the company's. Tied means either:
     *
     *  - the host is a domain an earlier source already verified as the company's (its
     *    official site), which covers a brand or abbreviated domain that does not spell
     *    the registered name; or
     *  - the host contains a token of the company's name.
     *
     * @param array<int, string> $domains verified company domains, lowercase, no `www.`
     *
     * @return array<int, string>
     */
    private function extractEmails(string $content, string $name, array $domains = []): array
    {
        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $content, $m);

        $nameTokens = array_values(array_filter(
            explode(' ', $this->normalizeToken(str_replace(['.', ',', '"'], ' ', $name))),
            static fn (string $t): bool => strlen($t) >= 4,
        ));
        if ($nameTokens === [] && $domains === []) {
            return []; // name too short/generic, and no verified domain to go by
        }

        $emails = [];
        foreach ($m[0] as $raw) {
            $email = strtolower($raw);
            $host = substr((string) strrchr($email, '@'), 1);
            if (in_array($host, self::EMAIL_NOISE, true) || str_starts_with($email, 'noreply') || str_starts_with($email, 'no-reply')) {
                continue;
            }
            if (EmailSanitizer::looksLikeFilename($email)) {
                continue; // e.g. soc@energitra.lt.asc — a PGP key filename, not an address
            }

            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    $emails[$email] = true;
                    continue 2;
                }
            }

            $hostNorm = $this->normalizeToken($host);
            foreach ($nameTokens as $token) {
                if (str_contains($hostNorm, $token)) {
                    $emails[$email] = true;
                    break;
                }
            }
        }

        return array_keys($emails);
    }

    /**
     * Valid-shaped Lithuanian numbers only, normalized to +370 form: the
     * subscriber part must start with a real prefix (6 mobile; 3/4/5/7 landline
     * areas), which rejects digit runs like "819582014" that merely look phone-ish.
     *
     * @return array<int, string>
     */
    private function extractPhones(string $content): array
    {
        preg_match_all('/(?:\+370|370|8)[\s\-()]{0,2}\d(?:[\s\-()]{0,2}\d){6,8}/', $content, $m);

        $phones = [];
        foreach ($m[0] as $raw) {
            $digits = preg_replace('/[^\d]/', '', $raw); // drop +, spaces, dashes

            // Reduce to the 8-digit national subscriber number.
            if (str_starts_with($digits, '370')) {
                $subscriber = substr($digits, 3);
            } elseif (str_starts_with($digits, '8')) {
                $subscriber = substr($digits, 1);
            } else {
                continue;
            }

            if (strlen($subscriber) === 8 && in_array($subscriber[0], ['3', '4', '5', '6', '7'], true)) {
                $phones['+370' . $subscriber] = true;
            }
        }

        return array_keys($phones);
    }

    private function normalizeToken(string $value): string
    {
        $map = ['ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i', 'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z'];
        $value = strtr(mb_strtolower($value), $map);

        return preg_replace('/[^a-z0-9 ]/', '', $value) ?? '';
    }
}
