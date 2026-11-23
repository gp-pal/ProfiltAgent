<?php

namespace App\Services;

use App\Models\CollectionAction;
use App\Models\CollectionRun;
use App\Models\CompanyProfile;
use App\Tools\CompanyNameTokens;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Local registry of collection activity: records each run and its actions, and
 * accumulates a best-known profile per company. It is the queryable
 * system-of-record + audit trail the universal collector writes to; the
 * filesystem artifacts remain the raw evidence.
 */
class RunRegistry
{
    /**
     * @param array{code?: ?string, name?: ?string, session?: ?string} $target
     */
    public function startRun(string $runHash, array $target): CollectionRun
    {
        return CollectionRun::updateOrCreate(
            ['run_hash' => $runHash],
            [
                'company_key' => $this->keyFor($target),
                'company_code' => $target['code'] ?? null,
                'name' => (string) ($target['name'] ?? ''),
                'session' => $target['session'] ?? null,
                'status' => 'running',
                'started_at' => now(),
            ],
        );
    }

    /**
     * @param array<string, mixed>|null $yielded
     * @param array<string, float>|null $fieldScores
     */
    public function recordAction(
        string $runHash,
        string $source,
        string $action,
        string $status = 'ok',
        ?array $yielded = null,
        ?array $fieldScores = null,
        ?string $detail = null,
    ): CollectionAction {
        return CollectionAction::create([
            'run_hash' => $runHash,
            'source' => $source,
            'action' => $action,
            'status' => $status,
            'yielded' => $yielded,
            'field_scores' => $fieldScores,
            'detail' => $detail,
        ]);
    }

    /**
     * @param array{sources_used?: array<int, string>, overall_score?: ?float, verdict?: ?string, status?: string} $summary
     */
    public function finishRun(string $runHash, array $summary): ?CollectionRun
    {
        $run = CollectionRun::where('run_hash', $runHash)->first();
        if ($run === null) {
            return null;
        }

        $run->update([
            'sources_used' => $summary['sources_used'] ?? $run->sources_used,
            'overall_score' => $summary['overall_score'] ?? $run->overall_score,
            'verdict' => $summary['verdict'] ?? $run->verdict,
            'status' => $summary['status'] ?? 'done',
            'finished_at' => now(),
        ]);

        return $run;
    }

    /**
     * Accumulate the target's best-known profile with newly collected fields.
     * Field-level merge: a field is (over)written only when the incoming value
     * carries STRICTLY more coverage than the stored one, so better evidence wins
     * but ties keep the earlier (more authoritative, legal-first) source — e.g. the
     * official site is not overwritten by an equally-covered open-web value. Each
     * kept field records the source it came from, and what that source's claim to be
     * this company rested on.
     *
     * @param array{code?: ?string, name?: ?string, domain?: ?string} $target
     * @param array<string, mixed> $fields
     * @param array<string, float> $coverage
     * @param array<string, string> $sources per-field source label
     * @param bool $authoritative bypass the coverage gate entirely: the caller-supplied
     *                            pre-merge passes true so a supplied value is recorded as
     *                            sent even when a previously stored value scores higher —
     *                            everyone else keeps the default "better evidence wins" gate.
     * @param ?MatchAssessment $assessment what the contributing source's identity rested on.
     *                                     Stored per field ({@see fieldVerification()}) so a
     *                                     later run reads what was established here rather
     *                                     than guessing.
     *
     *                                     Null records nothing, and the guarantee that comes
     *                                     with it is scoped to the fields whose values THIS
     *                                     merge stored: those are cleared, so they read
     *                                     "unknown" rather than inheriting the record of the
     *                                     value they replaced. A field this merge did not store
     *                                     — it tied, or scored lower — keeps its existing
     *                                     record, because that record still describes the value
     *                                     that is still stored. So a null-assessment merge does
     *                                     not make every field it touched read "unknown"; it
     *                                     makes every field it CHANGED read "unknown". What it
     *                                     can never do is make a field read verified.
     */
    public function mergeProfile(array $target, array $fields, array $coverage, array $sources, bool $authoritative = false, ?MatchAssessment $assessment = null): CompanyProfile
    {
        $profile = CompanyProfile::firstOrNew(['company_key' => $this->keyFor($target)]);

        $mergedFields = $profile->fields ?? [];
        $mergedCoverage = $profile->field_coverage ?? [];
        $mergedSources = $profile->field_sources ?? [];
        $mergedVerification = $profile->field_verification ?? [];

        foreach ($fields as $field => $value) {
            $newCov = (float) ($coverage[$field] ?? 0.0);
            $oldCov = (float) ($mergedCoverage[$field] ?? -1.0);

            // The caller is the authority on data it already holds: a supplied value is
            // recorded as sent, even when a previous run stored a longer one. Anything
            // else returns a value the caller did not send under a `supplied` label.
            if ($authoritative || $newCov > $oldCov) {
                $mergedFields[$field] = $value;
                $mergedCoverage[$field] = $newCov;
                $mergedSources[$field] = $sources[$field] ?? null;

                if ($assessment !== null) {
                    $mergedVerification[$field] = $this->fieldVerification($sources[$field] ?? null, $assessment);

                    continue;
                }

                // No assessment and a NEW value: the old record described the value that was
                // just replaced, so keeping it would report `verified` for something that is no
                // longer stored. Cleared, which reads as `unknown` — the only honest answer when
                // the writer told us nothing. Both current callers pass an assessment, so this is
                // the guard for the next one: a repair command or a backfill script that merges
                // without a verdict must not inherit the previous value's verification.
                unset($mergedVerification[$field]);

                continue;
            }

            // The value stays (a tie keeps the earlier, more authoritative source), but its
            // VERIFICATION can still be strengthened: a source we verified in this run
            // contributing the identical value corroborates what is stored, and refusing to
            // record that is what made a field permanently unverifiable — nothing may ever
            // relabel a tied field, so the verification would never move either. Only an
            // identical value counts: a verified source that contributed something else
            // verified its own value, not this one.
            if ($assessment !== null
                && $assessment->accepted
                && $assessment->verified()
                && ($mergedVerification[$field]['status'] ?? null) !== CompanyProfile::VERIFICATION_VERIFIED
                && $this->sameValue($mergedFields[$field] ?? null, $value)
            ) {
                $mergedVerification[$field] = $this->fieldVerification($sources[$field] ?? null, $assessment);
            }
        }

        $profile->fill([
            'company_code' => $target['code'] ?? $profile->company_code,
            'name' => $target['name'] ?? $profile->name,
            'domain' => $target['domain'] ?? $profile->domain,
            'fields' => $mergedFields,
            'field_coverage' => $mergedCoverage,
            'field_sources' => $mergedSources,
            'field_verification' => $mergedVerification,
            'overall_score' => $this->overall($mergedCoverage),
        ]);
        $profile->save();

        return $profile;
    }

    /**
     * One field's verification record: the status a reader acts on, plus who established it
     * and on what, so an audit does not have to reconstruct it from the run's action rows.
     *
     * `source` is who VERIFIED the value, which is not always who owns `field_sources` for it
     * — a corroborating source strengthens a tied field it does not own.
     *
     * @return array{status: string, source: ?string, verdict: string, basis: string, at: string}
     */
    private function fieldVerification(?string $source, MatchAssessment $assessment): array
    {
        return [
            'status' => $assessment->verified() && $assessment->accepted
                ? CompanyProfile::VERIFICATION_VERIFIED
                : CompanyProfile::VERIFICATION_UNVERIFIED,
            'source' => $source,
            'verdict' => $assessment->verdict,
            'basis' => $assessment->basis,
            'at' => now()->toIso8601String(),
        ];
    }

    /**
     * Whether an incoming value is the same value already stored — the test for corroboration
     * rather than for replacement. Lists are compared as sets: `social_links` order is an
     * artifact of how a page was parsed, not a difference in what was found.
     */
    private function sameValue(mixed $stored, mixed $incoming): bool
    {
        if (is_array($stored) && is_array($incoming)) {
            $left = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $stored);
            $right = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $incoming);
            sort($left);
            sort($right);

            return $left === $right;
        }

        return $stored === $incoming;
    }

    /**
     * Persist the news dimension on the profile WITHOUT touching the 6 coverage
     * fields, field_coverage, field_sources or overall_score. News is a separate,
     * point-in-time signal stored under fields['news'].
     *
     * The blob is NewsSource's whole news meta, so the receipt travels with the mentions:
     * what the search backend did, and who it did and did not ask about by name.
     *
     * @param array{code?: ?string, name?: ?string, domain?: ?string} $target
     * @param array{score: float, fresh: bool, mentions: array<int, array<string, mixed>>, search_state?: string, people_searched?: array<int, string>, people_dropped?: array<int, string>, people_dropped_reasons?: array<int, array{name: string, reason: string}>} $news
     */
    public function mergeNews(array $target, array $news): CompanyProfile
    {
        $profile = CompanyProfile::firstOrNew(['company_key' => $this->keyFor($target)]);

        $fields = $profile->fields ?? [];
        $fields['news'] = $news;

        $profile->fill([
            'company_code' => $target['code'] ?? $profile->company_code,
            'name' => $target['name'] ?? $profile->name,
            'domain' => $target['domain'] ?? $profile->domain,
            'fields' => $fields,
        ]);
        $profile->save();

        return $profile;
    }

    /**
     * Persist the company's people (directors / executives) under fields['people']
     * WITHOUT touching the 6 coverage fields, field_coverage, field_sources or
     * overall_score.
     *
     * Deliberately bypasses mergeProfile(): that accepts a field only when the
     * incoming coverage beats the stored coverage, and a non-coverage field always
     * scores 0.0 — so the first writer would win and every later list would be
     * silently dropped. Since CompanyRegistrySource runs first in the ladder, that would
     * make the registry manager permanently outrank the caller's current list.
     * Same reasoning and same shape as {@see mergeNews()}.
     *
     * The incoming list therefore stays authoritative about WHO the people are, additions and
     * removals alike: a person it does not name is dropped, because the current list is the
     * answer to "who runs this company now". What it is not authoritative about is SILENCE. A
     * null role states nothing about the role rather than "this person has none", so a role
     * already stated for a person the new list still names is kept
     * ({@see peopleKeepingStatedRoles()}) — the "absence must not overwrite presence" rule
     * {@see CollectorService::mergedPeople()} enforces WITHIN one run, enforced ACROSS runs.
     *
     * Without it the identical loss arrived through a second door: run A stored
     * `Vadovas / registry_db` from the registry; run B could not reach the registry (it is external,
     * so this is routine) and a caller named the same director without a title,
     * which left `mergedPeople()` no source entry to backfill from and overwrote a stated role
     * with null. Fixing it one level up rather than in the collector is deliberate: the loss is
     * a property of persisting a list over an older one, so every writer to this method is
     * covered — including a future backfill or repair command that never goes through
     * `mergedPeople()` at all.
     *
     * The limitation this leaves, stated here because it is the contract and not an implementation
     * detail: the two readings of a null role are indistinguishable by the time any merge sees the
     * value. `RunRequest` accepts `people.*.role` as nullable, and `RunController::normalizeInput()`
     * collapses a missing key, `null`, `""` and whitespace to the same `null`; the admin console does
     * the same. So a caller that genuinely knows a person holds NO title cannot say so, and a stored
     * role outlives its truth until some source states a different one. Documented for consumers
     * beside the `people[]` parameter in `docs/api/bootstrap-api.md`.
     *
     * @param array{code?: ?string, name?: ?string, domain?: ?string} $target
     * @param array<int, array{name: string, role: ?string, source: string}> $people
     */
    public function mergePeople(array $target, array $people): CompanyProfile
    {
        $profile = CompanyProfile::firstOrNew(['company_key' => $this->keyFor($target)]);

        if ($people !== []) {
            $fields = $profile->fields ?? [];
            $stored = $fields['people'] ?? null;
            $fields['people'] = $this->peopleKeepingStatedRoles($people, is_array($stored) ? $stored : []);
            $profile->fields = $fields;
        }

        $profile->fill([
            'company_code' => $target['code'] ?? $profile->company_code,
            'name' => $target['name'] ?? $profile->name,
            'domain' => $target['domain'] ?? $profile->domain,
        ]);
        $profile->save();

        return $profile;
    }

    /**
     * The incoming people list with every role it leaves unstated filled in from the stored
     * list, matched on the normalised name.
     *
     * Only the ROLE travels, and only into an entry that states none: a stated role is the
     * caller's to change, and a person the incoming list dropped is NOT resurrected here —
     * that is what keeps the stored list from permanently outranking the current one
     * ({@see mergePeople()}).
     *
     * Provenance is left exactly as the incoming entry states it, so a role recovered from a
     * registry-sourced row still reads `caller` when the caller is who named the person. That
     * is the labelling {@see CollectorService::mergedPeople()} already produces for the same
     * case within a single run; it is a separate recorded issue, and "fixing" it only here
     * would leave the two merges describing the same person differently.
     *
     * The key is {@see CompanyNameTokens::normalize()} — the one key every people merge in the
     * system dedupes on ({@see \App\Tools\RegistryPeople::ordered()},
     * {@see CollectorService::mergedPeople()}) — so spellings collapse here the same way they
     * do everywhere else, rather than one layer disagreeing with the next.
     *
     * @param array<int, array{name: string, role: ?string, source: string}> $incoming
     * @param array<int, mixed> $stored the people blob as it was last persisted
     *
     * @return array<int, array{name: string, role: ?string, source: string}>
     */
    private function peopleKeepingStatedRoles(array $incoming, array $stored): array
    {
        $storedRoles = [];

        foreach ($stored as $person) {
            if (! is_array($person)) {
                continue;
            }

            $key = CompanyNameTokens::normalize((string) ($person['name'] ?? ''));
            $role = trim((string) ($person['role'] ?? ''));

            if ($key === '' || $role === '') {
                continue;
            }

            // First entry wins, as it does in every other people merge: the list is held in
            // research order, so its head is the entry whose role we were surest of.
            $storedRoles[$key] ??= $role;
        }

        if ($storedRoles === []) {
            return $incoming;
        }

        foreach ($incoming as $index => $person) {
            if (! is_array($person) || trim((string) ($person['role'] ?? '')) !== '') {
                continue;
            }

            $key = CompanyNameTokens::normalize((string) ($person['name'] ?? ''));
            if ($key === '' || ! isset($storedRoles[$key])) {
                continue;
            }

            $incoming[$index]['role'] = $storedRoles[$key];
        }

        return $incoming;
    }

    public function profileFor(string $companyKey): ?CompanyProfile
    {
        return CompanyProfile::where('company_key', $companyKey)->first();
    }

    /**
     * @return Collection<int, CollectionRun>
     */
    public function runsFor(string $companyKey): Collection
    {
        return CollectionRun::where('company_key', $companyKey)->orderByDesc('id')->get();
    }

    /**
     * @param array{code?: ?string, name?: ?string} $target
     */
    public function keyFor(array $target): string
    {
        $code = trim((string) ($target['code'] ?? ''));
        if ($code !== '') {
            return 'code:' . $code;
        }

        return 'name:' . Str::slug((string) ($target['name'] ?? 'unknown'));
    }

    /**
     * @param array<string, float> $coverage
     */
    private function overall(array $coverage): float
    {
        if ($coverage === []) {
            return 0.0;
        }

        return round(array_sum($coverage) / count($coverage), 3);
    }
}
