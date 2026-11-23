<?php

namespace App\Services;

use App\Tools\CompanyNameTokens;
use App\Tools\PersonName;

/**
 * Builds the news-dimension search queries. Pure: every input is a parameter or
 * read-only config and nothing does I/O, so the whole query strategy is
 * unit-testable without a search backend.
 *
 * Company queries target the whitelist directly via `site:` OR groups (verified
 * to work on our SearXNG).
 *
 * Each person gets two forms, because they reach different articles. Paired with
 * the company name the query is self-constraining and needs no `site:` scoping,
 * but it only finds pieces that already name the employer — largely the ones the
 * company query found anyway. The name-only form reaches the rest, and it IS
 * site-scoped (to the first portal group only, so `people x portal-groups` cannot
 * multiply the query count) because a bare name otherwise matches every namesake
 * on the web.
 *
 * A person whose value is not purely a name gets no query at all and is reported by
 * name instead ({@see PersonName::isResearchable()}), because the registry glues the role
 * onto the name for most firms and a quoted phrase like "Indrė Šilkūnienė, direktorė"
 * matches no article ever written.
 *
 * This class only ever produces a PLAN. Which of these queries were really sent is
 * a runtime fact that belongs to {@see NewsSource}, the only layer that issues them
 * and knows when it stopped.
 */
class NewsQueryBuilder
{
    /**
     * @param array<int, string> $portals
     * @param array<int, array{name?: ?string, role?: ?string}> $people
     *
     * @return array{queries: array<int, array{q: string, subject: string, person: ?string}>, dropped: int, people_planned: array<int, string>, people_over_budget: array<int, string>, people_unusable: array<int, string>}
     */
    public function build(string $company, array $portals, array $people): array
    {
        $company = trim($company);
        $tokens = CompanyNameTokens::tokens($company);
        if ($company === '' || $tokens === []) {
            return [
                'queries' => [],
                'dropped' => 0,
                'people_planned' => [],
                'people_over_budget' => [],
                'people_unusable' => [],
            ];
        }

        $phrase = '"' . $this->distinctive($company, $tokens) . '"';
        $wanted = [];

        $perQuery = max(1, (int) config('react.news.portals_per_query', 5));
        $clean = array_values(array_filter(array_map('trim', $portals)));

        foreach (array_chunk($clean, $perQuery) as $chunk) {
            $sites = implode(' OR ', array_map(static fn (string $d): string => "site:{$d}", $chunk));
            $wanted[] = ['q' => "{$phrase} ({$sites})", 'subject' => 'company', 'person' => null];
        }

        // Every usable name, including the ones the caps will refuse: a person left out
        // is named in people_over_budget below, and a loop that breaks early has nothing
        // to report.
        //
        // Usable means the value is a name and nothing else. The registry glues the role
        // onto the name for two firms in three ("Indrė Šilkūnienė, direktorė"), and any
        // value still carrying one — or naming several people, or no person at all — makes
        // a quoted query no article can match. Those are collected apart and reported by
        // name, never searched: a query that cannot match still credits the person as
        // researched, which is the one thing the person report exists to prevent.
        //
        // Refused BEFORE the max_people ceiling, so an unusable value cannot spend the slot
        // of a director we could have researched.
        $names = [];
        $unusable = [];
        foreach ($people as $person) {
            $name = trim((string) ($person['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (! PersonName::isResearchable($name)) {
                $unusable[] = $name;

                continue;
            }

            $names[] = $name;
        }

        $maxPeople = max(0, (int) config('react.news.max_people', 3));

        foreach (array_slice($names, 0, $maxPeople) as $name) {
            // Two forms per person, because they reach different articles. Paired with
            // the company we find pieces that name the employer — but those are largely
            // the ones the company query already found. Name-only reaches the rest: an
            // appointment or a comment piece that never spells the company out. It is
            // site-scoped to the whitelist so a namesake elsewhere cannot flood it.
            $wanted[] = ['q' => "\"{$name}\" {$phrase}", 'subject' => 'person', 'person' => $name];

            if ($clean !== []) {
                $sites = implode(' OR ', array_map(
                    static fn (string $d): string => "site:{$d}",
                    array_slice($clean, 0, $perQuery),
                ));
                $wanted[] = ['q' => "\"{$name}\" ({$sites})", 'subject' => 'person', 'person' => $name];
            }
        }

        $max = max(1, (int) config('react.news.max_queries', 8));
        $queries = array_slice($wanted, 0, $max);

        // Derived from the queries that survived the ceiling, not from a prediction —
        // but still only a PLAN: not one of these queries has been issued yet, and the
        // wall-clock exit in NewsSource::collect() can cut the tail of the list off. So
        // `people_planned` is who we intend to ask about, and it is NOT the audit trail:
        // that is the source's job, because only it knows what it sent.
        //
        // The explicit callback says what may be dropped — the company rows' null person, and
        // nothing else. It was added because array_filter's default test drops every falsy
        // value, which had cost a person literally named "0" their place in this list after
        // their queries were built. No falsy name can reach here any more (isResearchable()
        // refuses '' and '0' above), so nothing fails if the callback is removed: it is
        // defence against that gate being loosened, not a live guard, and it is honest to say
        // so rather than to leave a comment claiming a bug it no longer prevents.
        $planned = array_values(array_unique(array_filter(
            array_column($queries, 'person'),
            static fn (?string $person): bool => $person !== null,
        )));

        return [
            'queries' => $queries,
            'dropped' => count($wanted) - count($queries),
            'people_planned' => $planned,
            // Refused by the ceilings before the run even starts, so this half of the
            // truth IS final here — the source only adds the people it failed to reach.
            'people_over_budget' => array_values(array_diff($names, $planned)),
            // Refused by their own value, whatever the budget: no run of any length would
            // have made these searchable.
            'people_unusable' => $unusable,
        ];
    }

    /**
     * How many people the configured ceilings will have a query for — asked of build()
     * itself, with placeholder names, rather than derived a second time.
     *
     * Public and static because the request console has to state the number BEFORE
     * anything runs. It used to recompute the rule (company groups, forms per person, the
     * max_people ceiling) in its own arithmetic, which is one rule with two
     * implementations: changing how many query forms a person gets above left the console
     * printing the old number with nothing failing, because only the direction of the
     * error was ever cross-checked. Nothing here does I/O, so asking build() costs a few
     * strings and makes that drift impossible instead of merely tested for.
     *
     * A ceiling on the PLAN, and exactly it: the wall-clock exit in
     * {@see NewsSource::collect()} and an unanswered query can both lower the number
     * actually researched, which is why the finished run reports the people it asked
     * about by name instead of leaving the operator with this estimate.
     *
     * @param array<int, string> $portals
     */
    public static function peopleBudget(array $portals): int
    {
        $maxPeople = max(0, (int) config('react.news.max_people', 3));
        if ($maxPeople === 0) {
            return 0;
        }

        // Placeholder names: the question is only how many of them survive build()'s
        // ceilings, and these never leave this method. Distinct, so the planned list
        // cannot collapse two of them into one — and spelled with letters only, because
        // build() now refuses a value that is not purely a name, and a digit in the probe
        // would make this answer 0 for reasons that have nothing to do with the budget.
        $probe = [];
        for ($i = 1; $i <= $maxPeople; $i++) {
            $probe[] = ['name' => 'Probe ' . str_repeat('Person', $i)];
        }

        return count((new self())->build('Probe Company', $portals, $probe)['people_planned']);
    }

    /**
     * The company name reduced to its distinctive part for phrase matching:
     * legal-form suffixes and trailing punctuation dropped ("Azerna, UAB" ->
     * "Azerna"), so the quoted phrase does not over-constrain the search.
     *
     * @param array<int, string> $tokens
     */
    private function distinctive(string $company, array $tokens): string
    {
        $words = preg_split('/\s+/', trim($company)) ?: [];
        $kept = [];

        foreach ($words as $word) {
            $bare = CompanyNameTokens::normalize($word);
            if ($bare !== '' && in_array($bare, $tokens, true)) {
                $kept[] = trim($word, " \t\n\r\0\x0B,.;:");
            }
        }

        return $kept === [] ? $company : implode(' ', $kept);
    }
}
