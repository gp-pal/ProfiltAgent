<?php

namespace App\Tools;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only access to an external company-registry database, scoped to selecting
 * "gap" firms — active companies that have employees (i.e. real, operating
 * businesses) yet carry no self-description and no contacts in the registry,
 * making them the highest-value targets for web enrichment.
 *
 * All queries go through Laravel's query builder: column names are only ever
 * drawn from fixed constants/literals below, never from user input, and every
 * value is parameter-bound by the builder — there is no raw SQL string
 * interpolation anywhere in this class.
 *
 * Expected schema — three tables or views on the `company_registry` connection.
 * Point the connection at a database that exposes them (a set of views over
 * whatever registry you hold is enough); nothing here writes to it.
 *
 *   firms                  code, name, address, employees, categories, manager,
 *                          work_hours, brand, brand_en, email, phone, mobile, x,
 *                          tiktok, skype, vat_code, is_vat_payer, registered_at,
 *                          deregistered_at, bankrupt, website, logo, description,
 *                          about_en
 *   firm_managers          code, manager          (several rows per firm)
 *   firm_current_managers  code, manager, second_manager, former_manager
 *
 * `categories` holds either plain text or a JSON object keyed by language
 * (`{"lt": "...", "en": "..."}`). A "real company" is inferred from
 * `employees > 0`.
 */
class CompanyRegistryRepository
{
    private const CONNECTION = 'company_registry';

    private const TABLE = 'firms';

    /**
     * Whitelisted columns for the rich {@see factsForCode()} lookup.
     *
     * @var list<string>
     */
    private const FACTS_COLUMNS = [
        'code', 'name', 'address', 'employees', 'categories', 'manager',
        'work_hours', 'brand_en', 'brand', 'email', 'phone', 'mobile', 'x',
        'tiktok', 'skype', 'vat_code', 'is_vat_payer', 'registered_at', 'bankrupt',
        'website', 'logo', 'about_en',
    ];

    /**
     * Verify the the registry connection is reachable. Throws on failure so callers
     * can fail fast with a clear prerequisite message.
     */
    public function assertReachable(): void
    {
        DB::connection(self::CONNECTION)->getPdo();
    }

    /**
     * Named people the registry knows for a firm, beyond the single
     * `firms.manager` the facts lookup returns.
     *
     * Two tables carry them and neither is assumed sufficient alone: `firm_managers`
     * may hold several rows per company (some of them blank), while
     * `firm_current_managers` holds at most two current managers, possibly upper-cased.
     * Neither states the role in a column of its own: a registry commonly glues it onto
     * the name ("VARDAS PAVARDĖ, Direktorius"). They are merged case-insensitively,
     * preferring the properly-cased spelling and keeping whichever entry knows the role.
     *
     * `former_manager` is included, marked `Buvęs vadovas`. Whether a leadership change
     * matters is the consumer's call, not ours — we only record that the registry lists
     * this person as the former manager, and the role keeps that distinguishable from
     * whoever runs the company today.
     *
     * @return array<int, array{name: string, role: ?string}>
     */
    public function peopleForCode(string $code): array
    {
        $people = [];

        $rows = DB::connection(self::CONNECTION)
            ->table('firm_managers')
            ->where('code', $code)
            ->limit(20)
            ->get(['manager']);

        foreach ($rows as $row) {
            $this->addRegistryEntry($people, (string) ($row->manager ?? ''));
        }

        $z = DB::connection(self::CONNECTION)
            ->table('firm_current_managers')
            ->where('code', $code)
            ->first(['manager', 'second_manager', 'former_manager']);

        foreach ([$z->manager ?? null, $z->second_manager ?? null] as $entry) {
            $this->addRegistryEntry($people, (string) ($entry ?? ''));
        }

        // Last, so a person who is both current and former keeps the current role.
        $this->addRegistryEntry($people, (string) ($z->former_manager ?? ''), 'Buvęs vadovas');

        return array_values($people);
    }

    /**
     * Add one raw registry manager string, with the role told apart from the name by
     * {@see PersonName} rather than by comma position.
     *
     * A string no name can be read out of is still added, unchanged: it is what the
     * registry holds, and dropping it here would leave the operator with no sign that
     * the firm has a manager entry at all. Whether such a value may be RESEARCHED is a
     * separate question, asked of {@see PersonName::isResearchable()} where the person
     * queries are planned, so an unusable value is reported by name instead of quietly
     * becoming a search for a phrase that cannot match.
     *
     * @param  array<string, array{name: string, role: ?string}>  $people
     * @param  ?string  $role  overrides the role the string states (the former-manager label)
     */
    private function addRegistryEntry(array &$people, string $entry, ?string $role = null): void
    {
        $parsed = PersonName::parse($entry);

        $this->addPerson(
            $people,
            $parsed['name'] ?? PersonName::collapse($entry),
            $role ?? $parsed['role'],
        );
    }

    /**
     * Merge one person into the accumulator, keyed on a case-folded name so the same
     * director written two ways collapses into one entry.
     *
     * @param  array<string, array{name: string, role: ?string}>  $people
     */
    private function addPerson(array &$people, string $name, ?string $role): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            return;
        }

        $key = mb_strtolower($name);
        $existing = $people[$key] ?? null;

        if ($existing === null) {
            $people[$key] = ['name' => $name, 'role' => $role];

            return;
        }

        // An all-caps spelling is the registry's storage form, not how the person is
        // written; prefer any variant that carries lower-case letters.
        if (mb_strtoupper($existing['name']) === $existing['name'] && mb_strtoupper($name) !== $name) {
            $people[$key]['name'] = $name;
        }

        $people[$key]['role'] ??= $role;
    }

    /**
     * Structural registry facts for a single firm by code — the legal, free
     * baseline the universal collector starts from (name/address/size/contacts),
     * before reaching to the web for description/contacts.
     *
     * @return array{
     *     code: string, name: string, address: ?string, employees: ?int,
     *     activity: ?string, manager: ?string, work_hours: ?string, brand: ?string,
     *     email: ?string, phone: ?string, mobile: ?string, x: ?string, tiktok: ?string,
     *     skype: ?string, vat_code: ?string, is_vat_payer: bool, registered: ?string,
     *     bankrupt: bool, site: ?string, logo: ?string, about: ?string,
     * }|null
     */
    public function factsForCode(string $code): ?array
    {
        $row = $this->query()
            ->where('code', $code)
            ->first(self::FACTS_COLUMNS);

        if ($row === null) {
            return null;
        }

        return [
            'code' => (string) $row->code,
            'name' => trim((string) $row->name),
            'address' => $this->nullableTrim($row->address ?? null),
            'employees' => $row->employees !== null ? (int) $row->employees : null,
            'activity' => $this->decodeCategories($row->categories ?? null),
            'manager' => $this->nullableTrim($row->manager ?? null),
            'work_hours' => $this->nullableTrim($row->work_hours ?? null),
            'brand' => $this->nullableTrim($row->brand_en ?? null) ?? $this->nullableTrim($row->brand ?? null),
            'email' => $this->nullableTrim($row->email ?? null),
            'phone' => $this->nullableTrim($row->phone ?? null),
            'mobile' => $this->nullableTrim($row->mobile ?? null),
            'x' => $this->nullableTrim($row->x ?? null),
            'tiktok' => $this->nullableTrim($row->tiktok ?? null),
            'skype' => $this->nullableTrim($row->skype ?? null),
            'vat_code' => $this->nullableTrim($row->vat_code ?? null),
            'is_vat_payer' => (bool) ($row->is_vat_payer ?? false),
            'registered' => $this->nullableTrim($row->registered_at ?? null),
            'bankrupt' => (bool) ($row->bankrupt ?? false),
            'site' => $this->nullableTrim($row->website ?? null),
            'logo' => $this->nullableTrim($row->logo ?? null),
            'about' => $this->nullableTrim($row->about_en ?? null),
        ];
    }

    /**
     * Resolve a firm's registry code when no code was supplied, so the collector
     * can pre-fill known fields (contacts, activity, …) from the registry instead of
     * re-searching the web for data already held. Conservative — never guesses:
     *   1. domain (if given): exact registrable-host match against `website`;
     *   2. else name: normalised (legal form/diacritics-insensitive) exact match,
     *      but ONLY when it resolves to a single firm — ambiguous names are skipped.
     * Returns the code, or null when there is no unambiguous match.
     */
    public function resolveCode(?string $domain, ?string $name): ?string
    {
        $domain = $this->normalizeDomain((string) ($domain ?? ''));
        if ($domain !== '') {
            $code = $this->codeByDomain($domain);
            if ($code !== null) {
                return $code;
            }
        }

        $name = trim((string) ($name ?? ''));

        return $name !== '' ? $this->codeByName($name) : null;
    }

    private function codeByDomain(string $domain): ?string
    {
        $rows = $this->query()
            ->whereNotNull('website')
            ->where('website', 'like', '%' . $domain . '%')
            ->limit(50)
            ->get(['code', 'website']);

        $codes = [];
        foreach ($rows as $row) {
            if ($this->normalizeDomain((string) ($row->website ?? '')) === $domain) {
                $codes[(string) $row->code] = true;
            }
        }

        return count($codes) === 1 ? (string) array_key_first($codes) : null;
    }

    private function codeByName(string $name): ?string
    {
        $target = $this->normalizeName($name);
        if ($target === '') {
            return null;
        }

        $rows = $this->query()
            ->where('name', 'like', '%' . $name . '%')
            ->limit(50)
            ->get(['code', 'name']);

        $codes = [];
        foreach ($rows as $row) {
            if ($this->normalizeName((string) ($row->name ?? '')) === $target) {
                $codes[(string) $row->code] = true;
            }
        }

        return count($codes) === 1 ? (string) array_key_first($codes) : null;
    }

    /** Registrable host of a URL/host string, lower-cased, www-stripped. */
    private function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (! str_contains($value, '://')) {
            $value = 'http://' . $value;
        }
        $host = (string) parse_url($value, PHP_URL_HOST);

        return (string) preg_replace('/^www\./', '', $host);
    }

    /** Company name reduced to distinctive tokens (diacritics + legal form dropped). */
    private function normalizeName(string $value): string
    {
        $value = Str::ascii(mb_strtolower(trim($value)));
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);
        $tokens = array_filter(
            explode(' ', trim($value)),
            static fn (string $t): bool => $t !== '' && ! in_array($t, ['uab', 'mb', 'ab', 'vsi', 'ii', 'vso', 'ke', 'tub', 'mab'], true),
        );

        return implode(' ', $tokens);
    }

    /**
     * Fetch gap firms: $withSite that have a website URL (fast, reliable capture)
     * plus $withoutSite that have none (exercise ReAct domain discovery). Ordered
     * by employee count so the most substantial companies come first.
     *
     * When $hard is true, pick the SMALLEST such firms (fewest employees) instead
     * of the largest — small, obscure companies with no self-description are the
     * genuine stress test for discovery + matching, whereas the largest gap firms
     * tend to be easy-to-find institutions.
     *
     * @return array<int, array{code: string, name: string, site: ?string, address: ?string}>
     */
    public function gapFirms(int $withSite, int $withoutSite, bool $hard = false): array
    {
        return array_merge(
            $this->select($withSite, hasSite: true, hard: $hard),
            $this->select($withoutSite, hasSite: false, hard: $hard),
        );
    }

    /**
     * Search gap firms for the picker: same gap definition as gapFirms(), with
     * optional text/site/size filters. Read-only, parameterized. Largest firms
     * first; result count clamped to a sane maximum.
     *
     * @param  array{q?: string, site?: string, size?: string}  $filters
     * @return array<int, array{code: string, name: string, site: ?string, address: ?string, employees: ?int}>
     */
    public function searchGapFirms(array $filters, int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));

        $rows = $this->exploring(fn () => $this->applySearchFilters($this->gapQuery(), $filters)
            ->orderByDesc('employees')
            ->limit($limit)
            ->get(['code', 'name', 'website', 'address', 'employees']));

        return $rows->map(fn (object $r): array => [
            'code' => (string) $r->code,
            'name' => trim((string) $r->name),
            'site' => $this->nullableTrim($r->website ?? null),
            'address' => $this->nullableTrim($r->address ?? null),
            'employees' => $r->employees !== null ? (int) $r->employees : null,
        ])->all();
    }

    /**
     * Reduce the `categories` column to a single display string. It may hold a
     * JSON object keyed by language, in which case the Lithuanian value is
     * preferred, or plain text, which is returned as it is.
     */
    private function decodeCategories(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            // Not JSON: the column holds the activity as plain text.
            return json_last_error() === JSON_ERROR_NONE ? null : trim($raw);
        }

        $value = $decoded['lt'] ?? $decoded['en'] ?? null;

        if ($value === null) {
            foreach ($decoded as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $value = $candidate;
                    break;
                }
            }
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Run an exploratory scan under a longer statement timeout, then restore the
     * request-path budget.
     *
     * The gap-firm predicate cannot use an index, so it legitimately takes tens of
     * seconds; the 5s cap that protects /api/run from a loaded registry kills it
     * outright. Raising the cap globally is not an option — that is exactly what
     * produced the 20-minute runs the cap was added to stop.
     *
     * The restore runs in `finally`: a timed-out scan throws, and leaving the
     * session on the long budget would silently hand it to every later enrichment
     * query on the same pooled connection.
     */
    private function exploring(callable $query): mixed
    {
        $connection = DB::connection(self::CONNECTION);

        // max_statement_time is MariaDB/MySQL syntax; tests point this connection at
        // SQLite, which has no statement timeout to raise.
        if ($connection->getDriverName() !== 'mysql') {
            return $query();
        }

        $long = max(1, (int) config('company_registry.exploratory_timeout_s', 30));
        $normal = max(1, (int) config('company_registry.timeout_s', 5));

        $connection->statement("SET SESSION max_statement_time={$long}");

        try {
            return $query();
        } finally {
            $connection->statement("SET SESSION max_statement_time={$normal}");
        }
    }

    /**
     * Base query builder scoped to the `firms` table.
     */
    private function query(): Builder
    {
        return DB::connection(self::CONNECTION)->table(self::TABLE);
    }

    /**
     * The shared "gap firm" predicate: active companies with employees, no
     * self-description, and no listed contacts.
     */
    private function gapQuery(): Builder
    {
        return $this->query()
            ->whereNull('deregistered_at')
            ->whereNotNull('name')
            ->where('name', '<>', '')
            ->where(fn (Builder $q) => $q->whereNull('description')->orWhere('description', ''))
            ->where(fn (Builder $q) => $q->whereNull('email')->orWhere('email', ''))
            ->where(fn (Builder $q) => $q->whereNull('phone')->orWhere('phone', ''))
            ->where(fn (Builder $q) => $q->whereNull('mobile')->orWhere('mobile', ''))
            ->where('employees', '>', 0);
    }

    /**
     * Apply the picker's optional q/site/size filters to a gap-firm query.
     *
     * @param  array{q?: string, site?: string, size?: string}  $filters
     */
    private function applySearchFilters(Builder $query, array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(fn (Builder $sub) => $sub->where('name', 'like', '%' . $q . '%')->orWhere('code', $q));
        }

        $site = (string) ($filters['site'] ?? 'any');
        if ($site === 'with') {
            $query->whereNotNull('website')->where('website', '<>', '');
        } elseif ($site === 'without') {
            $query->where(fn (Builder $sub) => $sub->whereNull('website')->orWhere('website', ''));
        }

        $size = (string) ($filters['size'] ?? 'any');
        if ($size === 'small') {
            $query->where('employees', '<', 10);
        } elseif ($size === 'medium') {
            $query->whereBetween('employees', [10, 100]);
        } elseif ($size === 'large') {
            $query->where('employees', '>', 100);
        }

        return $query;
    }

    /**
     * @return array<int, array{code: string, name: string, site: ?string, address: ?string}>
     */
    private function select(int $limit, bool $hasSite, bool $hard = false): array
    {
        if ($limit <= 0) {
            return [];
        }

        $query = $this->gapQuery();

        if ($hasSite) {
            $query->whereNotNull('website')->where('website', '<>', '');
        } else {
            $query->where(fn (Builder $sub) => $sub->whereNull('website')->orWhere('website', ''));
        }

        // `address` is the human-readable registry address (incl. city), which
        // lets the company-match judge distinguish the target from a same-named
        // company elsewhere (LT city vs a foreign address).
        $rows = $query
            ->orderBy('employees', $hard ? 'asc' : 'desc')
            ->limit($limit)
            ->get(['code', 'name', 'website', 'address']);

        return $rows->map(fn (object $r): array => [
            'code' => (string) $r->code,
            'name' => trim((string) $r->name),
            'site' => $this->nullableTrim($r->website ?? null),
            'address' => $this->nullableTrim($r->address ?? null),
        ])->all();
    }
}
