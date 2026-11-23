<?php

namespace App\Services;

use App\Models\CollectionRun;
use App\Models\CompanyProfile;
use App\Tools\CompanyNameTokens;
use App\Tools\RegistryPeople;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Universal target profiler: gathers company info from a legal-first source
 * ladder (registry DB -> official site -> open web), accumulating the best-known
 * profile until the per-field coverage score reaches its target or sources run
 * out. Every source's contribution is match-gated (must be the target company)
 * and recorded in the run registry with provenance — and with the match verdict that
 * gate rested on, {@see MatchAssessment}, so an accepted source can be audited later
 * instead of taken on trust.
 */
class CollectorService
{
    /**
     * The one source whose identity is an authoritative key rather than a judge's verdict:
     * a registry row keyed by company code. Named because three separate rules turn on it
     * — whose people we trust without a match verdict, whose fields need no verdict, and
     * whose `manager` column may imply a role ({@see peopleFrom()}).
     */
    private const REGISTRY_SOURCE = 'registry_db';

    /** Flags for every stored `detail` blob: readable Lithuanian text and URLs in the audit trail. */
    private const DETAIL_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @var array<int, CollectionSource> legal-first order */
    private array $sources;

    public function __construct(
        private RunRegistry $registry,
        private ProfileCoverageScorer $scorer,
        private CompanyMatchJudge $judge,
        private FieldRequirements $requirements,
        CompanyRegistrySource $companyRegistry,
        OfficialSiteSource $officialSite,
        OpenWebSource $openWeb,
        ContactsSource $contacts,
        private NewsSource $news,
    ) {
        $this->sources = [$companyRegistry, $officialSite, $openWeb, $contacts];
    }

    /**
     * @param array{code?: ?string, name?: ?string, address?: ?string, country?: ?string, domain?: ?string, session?: ?string, people?: array<int, array{name?: ?string, role?: ?string}>, fields?: array<int, string>, known_fields?: array<string, string|array<int, string>>, verify_fields?: ?bool} $target
     *
     * @return array{run_hash: string, profile: array<string, mixed>, field_sources: array<string, mixed>, coverage: array{overall: float, fields: array<string, float>, missing: array<int, string>}, sources_used: array<int, string>, verdict: string, field_status: array<string, string>, field_verification: array<string, array{status: string, source: ?string, verdict: ?string, basis: ?string, at: ?string}>, match_verdicts: array<string, array{accepted: bool, verdict: string, basis: string, verified: bool, derived_from: ?string, match_confidence: ?float, data_coverage: ?float, reasons: array<int, string>}>}
     */
    public function collect(array $target): array
    {
        $runHash = (string) Str::uuid();
        $this->registry->startRun($runHash, $target);

        try {
            return $this->runLadder($runHash, $target);
        } catch (\Throwable $e) {
            // Close the run we opened: an exception past this point used to leave the
            // row `running` forever, which the panel showed as a collection still in
            // progress. The exception itself still reaches the caller.
            $this->registry->finishRun($runHash, ['status' => 'failed']);

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $target
     *
     * @return array<string, mixed>
     */
    private function runLadder(string $runHash, array $target): array
    {
        $declared = $this->requirements->resolve(is_array($target['fields'] ?? null) ? $target['fields'] : []);
        $target['declared_fields'] = $declared;

        // Values the caller already holds: recorded with `caller` provenance up front so
        // they leave $coverage['missing'] and no source is asked for them. Restricted to
        // declared fields — a value for something nobody asked for is noise.
        $supplied = $this->coerceSupplied(array_intersect_key(
            is_array($target['known_fields'] ?? null) ? $target['known_fields'] : [],
            array_flip($declared),
        ));

        // With the score threshold gone, wall-clock is the only stop besides "all
        // declared fields filled". The first source always runs.
        $deadline = microtime(true) + max(0, (int) config('react.coverage.ladder_budget_s', 120));

        // Run the sources even when there is nothing left to collect, so they can establish
        // verification for what is already stored ({@see RunRegistry::mergeProfile()}
        // corroboration). The caller asks for this; nothing turns it on by itself.
        $verifyFields = (bool) ($target['verify_fields'] ?? false);
        $budgetSpent = false;
        $laddered = 0;

        $sourcesUsed = [];
        $sourcePeople = [];
        /**
         * Per-source match verdict for this run: returned so the caller sees what each
         * contribution rests on, and carried into mergeProfile() so each field it contributes
         * stores what its acceptance rested on.
         *
         * @var array<string, MatchAssessment>
         */
        $assessed = [];
        $overall = 0.0;
        // A truthful score of "nothing collected yet": every declared field is a gap,
        // except one already resolved by provenance (see coverageFor()). The previous
        // placeholder claimed missing === [] before anything ran, which is a lie the
        // ladder-continuation check below had to patch around.
        $coverage = $this->coverageFor([], $declared, $supplied);
        $hadRecoverableError = false;

        if ($supplied !== []) {
            $suppliedCoverage = $this->scorer->score($supplied, $declared)['fields'];
            $profile = $this->registry->mergeProfile(
                $target,
                $supplied,
                $suppliedCoverage,
                array_fill_keys(array_keys($supplied), 'caller'),
                authoritative: true,
                // The caller's own assertion of its own data: recorded as verified under its
                // own basis, not left blank, so a later run reading these fields does not
                // mistake "the caller sent it" for "nobody knows".
                assessment: MatchAssessment::callerSupplied('known_fields'),
            );
            $coverage = $this->coverageFor($profile->fields ?? [], $declared, $supplied);
            $overall = $coverage['overall'];
            // Mirror the per-source loop's persist below (:160): a run where nothing
            // else ever contributes (e.g. every declared field was supplied, or the
            // remaining sources yield nothing) never reaches that line, so this is
            // the only place CompanyProfile.overall_score gets the gap-aware value —
            // otherwise it keeps mergeProfile()'s naive average over only the
            // supplied keys, which disagrees with CollectionRun.overall_score.
            $profile->update(['overall_score' => $overall]);
        }

        foreach ($this->sources as $source) {
            if ($laddered > 0 && microtime(true) >= $deadline) {
                $budgetSpent = true;
                Log::info('Collector ladder budget spent', [
                    'sources_run' => $laddered,
                    'skipped' => count($this->sources) - $laddered,
                ]);
                break;
            }
            // Every declared field is filled or supplied, so there is nothing left to COLLECT.
            // A verification pass has something left to do anyway: for an already-enriched firm
            // the registry alone empties `missing`, so the ladder stops at the first source and
            // a field only `official_site` can speak for keeps whatever verification record it
            // has — `unknown`, for every value written before we stored one — on every future
            // run. Asking for the pass is the way out; it is opt-in because the default path
            // must not spend a browser capture and an LLM call to re-confirm data it already
            // holds. The wall-clock check above still bounds it, and no budget changes.
            if ($laddered > 0 && $coverage['missing'] === [] && !$verifyFields) {
                break;
            }
            $laddered++;

            if (!$source->supports($target)) {
                $this->registry->recordAction($runHash, $source->name(), 'collect', 'skipped');
                continue;
            }

            $result = $source->collect($target);
            $incomingCoverage = $this->scorer->score($result->fields, $declared)['fields'];
            $assessment = $this->assessMatch($source, $result, $target);
            $accepted = $assessment?->accepted ?? false;

            if ($assessment !== null) {
                $assessed[$source->name()] = $assessment;

                // An acceptance the operator's log cannot account for is what §11.1 of the
                // live verification could not answer after the fact. Logged at warning level
                // when nothing verified it, so an LLM outage is visible while it is happening
                // and not only in the stored row afterwards.
                Log::log($assessment->verified() ? 'info' : 'warning', 'Collector match verdict', [
                    'source' => $source->name(),
                    'company' => $target['name'] ?? null,
                    'accepted' => $assessment->accepted,
                    'verdict' => $assessment->verdict,
                    'basis' => $assessment->basis,
                    'derived_from' => $assessment->derivedFrom,
                    'match_confidence' => $assessment->matchConfidence,
                ]);
            }

            // Audit status: keep the source's own status (empty/skipped/error);
            // only an 'ok' result that fails the match gate is logged 'rejected'.
            $auditStatus = $accepted ? $result->status : ($result->status === 'ok' ? 'rejected' : $result->status);

            // A source that errored is a recoverable gap (a retry could still fill
            // it); 'empty'/'rejected'/'skipped' are clean outcomes. This is what
            // separates a 'partial' run from a saturated 'data_limited' one.
            if ($auditStatus === 'error') {
                $hadRecoverableError = true;
            }

            $this->registry->recordAction(
                $runHash,
                $source->name(),
                'collect',
                $auditStatus,
                $result->fields,
                $incomingCoverage,
                $this->actionDetail($result->meta, $assessment),
            );

            // Who runs the company, gated on IDENTITY rather than on field yield: the
            // registry row is keyed by company code, so its people are just as
            // trustworthy when mapFields() produced nothing we declared. For a source
            // whose identity rests on the match judge, no verdict means no names — a
            // stranger's director is not ours.
            //
            // This OR-condition — and accepted()'s 'registry_db' arm below, which the
            // same reasoning drives — may only ever be extended to a source whose
            // identity is guaranteed by an authoritative key (a registry row keyed by
            // company code, for example). It must never be extended to a source that
            // reaches identity through $this->judge: that identity is a verdict, not a
            // fact, and bypassing the judge for it would accept a stranger's fields as
            // ours on nothing more than a guess.
            if ($result->status === 'ok' && ($accepted || $source->name() === self::REGISTRY_SOURCE)) {
                $sourcePeople = array_merge($sourcePeople, $this->peopleFrom($source->name(), $result->meta));
            }

            if (!$accepted) {
                continue;
            }

            $sourcesUsed[] = $source->name();

            // The official site passed the match gate, so its domain is the company's.
            // Later sources may attribute contacts to it even when the domain does not
            // spell the registered name (a brand domain, an abbreviation).
            if ($source->name() === 'official_site') {
                $verifiedDomain = strtolower(trim((string) ($result->meta['domain'] ?? '')));
                $verifiedDomain = (string) preg_replace('/^www\./', '', $verifiedDomain);
                if ($verifiedDomain !== '') {
                    $target['verified_domains'] = array_values(array_unique([
                        ...(array) ($target['verified_domains'] ?? []),
                        $verifiedDomain,
                    ]));
                }
            }

            // Rule 3: a caller-supplied field is never overwritten by a source.
            $contributed = array_diff_key($result->fields, $supplied);

            // The profile's own `domain` used to come from the caller alone, so a site
            // this run found and verified was never stored on it.
            if (trim((string) ($target['domain'] ?? '')) === '' && ($target['verified_domains'] ?? []) !== []) {
                $target['domain'] = $target['verified_domains'][0];
            }

            $profile = $this->registry->mergeProfile(
                $target,
                $contributed,
                $this->scorer->score($contributed, $declared)['fields'],
                array_fill_keys(array_keys($contributed), $source->name()),
                assessment: $assessment,
            );

            // Persist the gap-aware overall (scored across the full canonical field
            // set, missing fields = 0) so the registry's profile score matches the
            // manifest and honestly reflects completeness — not just the average of
            // the fields that happen to be present.
            $coverage = $this->coverageFor($profile->fields ?? [], $declared, $supplied);
            $overall = $coverage['overall'];
            $profile->update(['overall_score' => $overall]);
        }

        // A run cut short by our own budget is retryable, so it must never be
        // reported as the firm's data ceiling.
        if ($coverage['missing'] === []) {
            $verdict = 'sufficient';
        } elseif ($sourcesUsed === []) {
            $verdict = 'none';
        } elseif ($budgetSpent || $hadRecoverableError) {
            $verdict = 'partial';
        } else {
            $verdict = CollectionRun::VERDICT_DATA_LIMITED;
        }

        // People (directors / executives) are stored like news: alongside the
        // coverage fields, never inside them, so they cannot move the score.
        $people = $this->mergedPeople($target['people'] ?? [], $sourcePeople);
        if ($people !== []) {
            $this->registry->mergePeople($target, $people);
        }

        // The same list is what the news dimension researches. It used to stay local, so
        // a director the registry named was stored and then never looked for: the news
        // phase read $target['people'], i.e. the caller's list alone, and not one profile
        // in the database carried a person-attributed mention. Assigned AFTER the merge
        // above so an exception in the news phase cannot cost us people we already know.
        $target['people'] = $people;

        // News runs unconditionally (not gated by the coverage target) and is
        // stored separately; it never enters overall/coverage/verdict. Verdict
        // was computed above from coverage sources only, so a news hit can't flip
        // it (e.g. 'none' -> 'data_limited').
        if ($this->news->supports($target)) {
            $newsResult = $this->news->collect($target);
            $this->registry->recordAction(
                $runHash,
                'news',
                'collect',
                $newsResult->status,
                null,
                null,
                // No match block: news is not match-gated (its own namesake gate decides
                // what counts as a mention), so there is no verdict to record here.
                $this->actionDetail($newsResult->meta, null),
            );
            if (($newsResult->meta['news']['mentions'] ?? []) !== []) {
                $sourcesUsed[] = 'news';
                $this->registry->mergeNews($target, $newsResult->meta['news']);
            }
        }

        $this->registry->finishRun($runHash, [
            'sources_used' => $sourcesUsed,
            'overall_score' => $overall,
            'verdict' => $verdict,
            'status' => 'done',
        ]);

        $profile = $this->registry->profileFor($this->registry->keyFor($target));

        $fieldStatus = $this->fieldStatus($declared, $profile?->field_sources ?? [], $coverage['missing'], $budgetSpent);

        return [
            'run_hash' => $runHash,
            'profile' => $profile?->fields ?? [],
            'field_sources' => $profile?->field_sources ?? [],
            // Per-field provenance's sibling: what is known about each stored value's match
            // verification, including for values an earlier run contributed. A consumer reading
            // `field_sources` to decide whose data this is needs this to decide what it is
            // worth; 'unknown' means we hold no record, which is never "verified".
            'field_verification' => $this->fieldVerification($profile, $declared),
            'coverage' => $coverage,
            'sources_used' => $sourcesUsed,
            'verdict' => $verdict,
            'field_status' => $fieldStatus,
            // The consumer reads provenance per field; this says what that provenance was
            // worth. `verified: false` under a source means its fields were taken without a
            // working match check, which a caller merging them into a CRM needs to know.
            'match_verdicts' => array_map(
                static fn (MatchAssessment $assessment): array => $assessment->toArray(),
                $assessed,
            ),
        ];
    }

    /**
     * What is known about the verification of each declared field the profile actually holds.
     * Resolved through {@see CompanyProfile::verificationOf()}, so a field with no stored record
     * comes back `unknown` rather than absent — the consumer and the console then have one shape
     * to read, and neither can mistake a missing key for a verified value.
     *
     * @param array<int, string> $declared
     *
     * @return array<string, array{status: string, source: ?string, verdict: ?string, basis: ?string, at: ?string}>
     */
    private function fieldVerification(?CompanyProfile $profile, array $declared): array
    {
        if ($profile === null) {
            return [];
        }

        $held = $profile->fields ?? [];
        $verification = [];

        foreach ($declared as $field) {
            if (array_key_exists($field, $held)) {
                $verification[$field] = $profile->verificationOf($field);
            }
        }

        return $verification;
    }

    /**
     * Coerce caller-supplied values to the catalogue's declared type. The caller's
     * shape is preserved untouched from the request, and a supplied value is
     * authoritative — no source ever overwrites it and nothing re-derives it — so
     * `{"emails": "info@acmena.lt"}` would otherwise be returned AND persisted as a
     * string in a field the consumer's schema declares a list.
     *
     * A key with no catalogue entry (only reachable on a deploy whose catalogue was
     * emptied, where the declared set falls back to the legacy six) has no declared
     * type, so it is left exactly as the caller sent it rather than guessed at.
     *
     * @param  array<string, mixed>  $supplied
     * @return array<string, mixed>
     */
    private function coerceSupplied(array $supplied): array
    {
        $catalogue = $this->requirements->catalogue();

        foreach ($supplied as $key => $value) {
            $type = $catalogue[$key]['type'] ?? null;

            if ($type === 'array' && !is_array($value)) {
                // Split on commas: a caller (or the admin console's key/value table) types
                // several emails or brands in one line, and wrapping that whole string as a
                // single element would store "a@b.lt, c@d.lt" as one address.
                $supplied[$key] = is_scalar($value)
                    ? array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== ''))
                    : [];
            } elseif ($type !== null && $type !== 'array' && is_array($value)) {
                $supplied[$key] = implode(', ', array_filter(array_map(
                    static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '',
                    $value,
                ), static fn (string $v): bool => $v !== ''));
            }
        }

        return $supplied;
    }

    /**
     * Coverage as the ladder must read it: a caller-supplied field is resolved by
     * provenance, not by score. Rule 2 of the known_fields contract forbids searching
     * for it, so it can never count as a gap — otherwise a short supplied value (prose
     * fields score on length, not presence) would keep the whole ladder running for data
     * the caller already handed us. Its real score stays in `fields`, so a thin supplied
     * value is still visible to the consumer; it just is not our gap to close.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<int, string>  $declared
     * @param  array<string, mixed>  $supplied
     * @return array{fields: array<string, float>, overall: float, missing: array<int, string>}
     */
    private function coverageFor(array $profile, array $declared, array $supplied): array
    {
        $coverage = $this->scorer->score($profile, $declared);
        $coverage['missing'] = array_values(array_diff($coverage['missing'], array_keys($supplied)));

        return $coverage;
    }

    /**
     * Per-declared-field outcome the consumer can act on:
     *   supplied  — the caller sent it; we never searched
     *   found     — we searched and filled it
     *   not_found — we ran the whole ladder and it is not publicly available
     *
     * A field left unresolved because our own budget ran out is deliberately absent
     * from the map: reporting `not_found` there would state as fact that the company
     * has no such data when we simply stopped looking.
     *
     * @param array<int, string> $declared
     * @param array<string, mixed> $sources
     * @param array<int, string> $missing
     *
     * @return array<string, string>
     */
    private function fieldStatus(array $declared, array $sources, array $missing, bool $budgetSpent): array
    {
        $status = [];

        foreach ($declared as $field) {
            if (($sources[$field] ?? null) === 'caller') {
                $status[$field] = 'supplied';
                continue;
            }
            if (!in_array($field, $missing, true)) {
                $status[$field] = 'found';
                continue;
            }
            if (!$budgetSpent) {
                $status[$field] = 'not_found';
            }
        }

        return $status;
    }

    /**
     * Caller-supplied people take precedence over registry-derived ones: the
     * orchestrating agent knows the current roles, while a registry row can be
     * years stale. Deduplicated on the normalised name so the same person never
     * appears twice under two spellings of their role.
     *
     * That precedence is over VALUES, not over silence. A caller entry with no role does not
     * state "this person has no role", it states nothing about the role at all, so it must
     * not erase a role a source did state for the same person — a caller who names the
     * director without their title used to downgrade `Vadovas / registry_db` to `null`,
     * losing data the registry had already handed us. Any caller is covered, not only the
     * admin console: the rule belongs to the merge.
     *
     * This half only reaches roles THIS run collected. The same silence can arrive over a role
     * an EARLIER run stored — a run that cannot reach the registry leaves nothing here to backfill
     * from — so {@see RunRegistry::mergePeople()} enforces the identical rule against the stored
     * list. Neither is redundant: this one decides the list the news dimension researches, that
     * one decides what survives in the profile.
     *
     * @param array<int, array{name?: ?string, role?: ?string}> $callerPeople
     * @param array<int, array{name: string, role: ?string, source: string}> $sourcePeople
     *
     * @return array<int, array{name: string, role: ?string, source: string}>
     */
    private function mergedPeople(array $callerPeople, array $sourcePeople): array
    {
        $merged = [];

        foreach ($callerPeople as $person) {
            $name = trim((string) ($person['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $merged[CompanyNameTokens::normalize($name)] = [
                'name' => $name,
                'role' => ($person['role'] ?? null) ?: null,
                'source' => 'caller',
            ];
        }

        foreach ($sourcePeople as $person) {
            $key = CompanyNameTokens::normalize($person['name']);
            if ($key === '') {
                continue;
            }

            if (! isset($merged[$key])) {
                $merged[$key] = $person;

                continue;
            }

            $merged[$key]['role'] ??= $person['role'];
        }

        return array_values($merged);
    }

    /**
     * The people a source reported, in research order and stamped with its provenance.
     *
     * The order itself is not decided here: it is {@see RegistryPeople::ordered()}, the one
     * implementation of that rule, which `/admin/request-console` prefills from as well so the
     * console cannot send a differently-ordered list for the same firm. Order is the budget —
     * see that class for why the manager column has to lead.
     *
     * `manager` is read from the REGISTRY ONLY, and that is a correctness rule rather than a
     * tidiness one. `RegistryPeople` implies the role 'Vadovas' for a manager column that
     * states none, which is true of `firms.manager` — that column IS the registry's
     * manager field — and true of nothing else. Read from a web source (an About-page
     * extractor being the obvious candidate) the same line would INVENT a role for a person
     * whose page never gave one, and store it indistinguishably from a role the registry
     * stated. Order makes it worse: the manager column takes the head of the queue, so an
     * unvetted guess would spend the run's single unit of person research.
     *
     * A non-registry source that learns who runs a company therefore reports them in
     * `meta['people']`, where a role is carried only when the source actually read one. The
     * `manager` key of such a source is left alone here — we have no rule for reading it —
     * while staying in the recorded action meta for the audit trail.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<int, array{name: string, role: ?string, source: string}>
     */
    private function peopleFrom(string $source, array $meta): array
    {
        $people = RegistryPeople::ordered(
            $source === self::REGISTRY_SOURCE && isset($meta['manager']) ? (string) $meta['manager'] : null,
            (array) ($meta['people'] ?? []),
        );

        return array_map(
            static fn (array $person): array => $person + ['source' => $source],
            $people,
        );
    }

    /**
     * A source's contribution is accepted only if it is the target company. The
     * registry DB is trusted (keyed by code); the official site carries its own
     * match verdict; open web and contacts are judged here against the target facts.
     *
     * Returns the VERDICT alongside the decision, never the decision alone: every arm
     * below reaches its answer through something, and that something has to end up in the
     * stored action ({@see actionDetail()}) or an audit cannot tell an acceptance backed by
     * a real `pass` from one backed by the judge's fail-open. The `default`
     * arm used to call the judge inline and discard the answer — that gap is the reason
     * this returns {@see MatchAssessment}.
     *
     * The accept/reject decision itself is deliberately UNCHANGED, fail-open included: only
     * a definite `fail` rejects, and a missing verdict still accepts. Making the judge fail
     * closed would stop collection dead during an LLM outage, which is an operational trade
     * to be made on purpose rather than smuggled in as a visibility fix.
     *
     * @param array<string, mixed> $target
     *
     * @return MatchAssessment|null null when no match decision was reached at all: the
     *                              source errored, was skipped or yielded nothing, so there
     *                              is no contribution for a verdict to be about
     */
    private function assessMatch(CollectionSource $source, CollectionResult $result, array $target): ?MatchAssessment
    {
        if ($result->status !== 'ok' || $result->fields === []) {
            return null;
        }

        $evidence = [
            'domain' => $result->meta['domain'] ?? null,
            'content' => $result->meta['content'] ?? null,
            'profile' => $result->fields,
        ];

        return match ($source->name()) {
            self::REGISTRY_SOURCE => MatchAssessment::registryCode(),
            'official_site' => $this->storedVerdict($result->meta['quality'] ?? null),
            default => $this->judgedHere($target, $evidence),
        };
    }

    /**
     * Ask the judge and keep its answer. `rejects()` stays the one implementation of the
     * accept/reject rule — it is what the domain-resolution gate uses too — so recording the
     * verdict cannot drift from acting on it.
     *
     * @param array<string, mixed> $target
     * @param array{domain: ?string, content: ?string, profile: array<string, mixed>} $evidence
     */
    private function judgedHere(array $target, array $evidence): MatchAssessment
    {
        $verdict = $this->judge->judge($target, $evidence);

        return $this->assessmentFrom($verdict, !$this->judge->rejects($verdict), MatchAssessment::BASIS_JUDGE);
    }

    /**
     * `official_site` carries the verdict the profile pipeline already obtained, in
     * `meta['quality']`. A profile built without one — a `profile.json` rehydrated by
     * CHECK_ASSETS from a run that predates the judge, most plainly — used to default to
     * 'warn' and be accepted with no match verification recorded anywhere, which is
     * indistinguishable in storage from a judge saying "maybe".
     *
     * The decision is preserved exactly: `($quality['verdict'] ?? 'warn') !== 'fail'`.
     */
    private function storedVerdict(mixed $quality): MatchAssessment
    {
        $accepted = (is_array($quality) ? ($quality['verdict'] ?? 'warn') : 'warn') !== 'fail';

        if (!is_array($quality)) {
            return new MatchAssessment(
                accepted: $accepted,
                verdict: MatchAssessment::VERDICT_UNVERIFIED,
                basis: MatchAssessment::BASIS_NO_VERDICT,
                reasons: ['the built profile carried no match verdict'],
            );
        }

        return $this->assessmentFrom($quality, $accepted, MatchAssessment::BASIS_STORED_VERDICT);
    }

    /**
     * Classify a judge-shaped verdict blob into a recordable assessment.
     *
     * A verdict counts only when something produced it. `verified` states that outright on
     * anything the current judge returns; a blob written before that key existed is read by
     * its signature instead — the fail-open (and a model answer with an unusable
     * match_confidence) is exactly a 'warn' with no confidence, which no real judgement can
     * be, since 'pass' and 'fail' both require a number to have been derived from.
     *
     * @param array<string, mixed> $verdict
     */
    private function assessmentFrom(array $verdict, bool $accepted, string $basis): MatchAssessment
    {
        $stated = is_string($verdict['verdict'] ?? null) ? trim($verdict['verdict']) : '';
        $confidence = is_numeric($verdict['match_confidence'] ?? null) ? (float) $verdict['match_confidence'] : null;
        $coverage = is_numeric($verdict['data_coverage'] ?? null) ? (float) $verdict['data_coverage'] : null;

        $reasons = [];
        foreach ((array) ($verdict['reasons'] ?? []) as $reason) {
            if (is_string($reason) && trim($reason) !== '') {
                $reasons[] = trim($reason);
            }
        }

        if ($stated === '') {
            return new MatchAssessment(
                accepted: $accepted,
                verdict: MatchAssessment::VERDICT_UNVERIFIED,
                basis: MatchAssessment::BASIS_NO_VERDICT,
                reasons: $reasons === [] ? ['no match verdict was produced'] : $reasons,
            );
        }

        $backed = is_bool($verdict['verified'] ?? null)
            ? $verdict['verified']
            : ($stated !== 'warn' || $confidence !== null);

        if (!$backed) {
            return new MatchAssessment(
                accepted: $accepted,
                verdict: MatchAssessment::VERDICT_UNVERIFIED,
                basis: MatchAssessment::BASIS_JUDGE_UNAVAILABLE,
                matchConfidence: $confidence,
                dataCoverage: $coverage,
                reasons: $reasons === [] ? ['the judge produced no usable verdict'] : $reasons,
            );
        }

        return new MatchAssessment(
            accepted: $accepted,
            verdict: $stated,
            basis: $basis,
            matchConfidence: $confidence,
            dataCoverage: $coverage,
            reasons: $reasons,
        );
    }

    /**
     * What gets stored in the action's `detail`: the source's own meta, plus — whenever a
     * match decision was reached — the verdict it rested on under `match`.
     *
     * The match block is re-encoded on its own if the meta cannot be encoded, which happens
     * for real: `json_encode()` returns false on invalid UTF-8, and scraped page content
     * sits in that meta. Losing the whole detail to a broken byte in someone's page title
     * would silently reopen the very audit gap this block closes, so the verdict survives
     * the meta. array_merge, not `+`, so our key wins over any `match` a source ever sets.
     *
     * @param array<string, mixed> $meta
     */
    private function actionDetail(array $meta, ?MatchAssessment $assessment): ?string
    {
        if ($assessment !== null) {
            $meta = array_merge($meta, ['match' => $assessment->toArray()]);
        }

        $encoded = json_encode($meta, self::DETAIL_JSON_FLAGS);
        if ($encoded !== false) {
            return $encoded;
        }

        if ($assessment === null) {
            return null;
        }

        return json_encode(['match' => $assessment->toArray()], self::DETAIL_JSON_FLAGS) ?: null;
    }
}
