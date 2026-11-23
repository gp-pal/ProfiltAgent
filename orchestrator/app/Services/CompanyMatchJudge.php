<?php

namespace App\Services;

use App\IO\Contracts\LlmClient;
use Illuminate\Support\Facades\Log;

/**
 * LLM-as-judge that verifies a resolved domain / built profile actually belongs
 * to the TARGET company, and rates how complete the collected information is.
 *
 * The model returns match_confidence + data_coverage (both 0..1) + reasons; the
 * verdict (pass/warn/fail) is derived deterministically from match_confidence
 * against the configured thresholds. Fail-open: when disabled or the LLM errors,
 * it returns a non-blocking "warn" so a judge outage never aborts a run — carrying
 * `verified: false`, which is how a caller (and its audit trail) tells that warn
 * apart from one the model actually reasoned its way to. See {@see MatchAssessment}.
 */
class CompanyMatchJudge
{
    /**
     * JSON schema for the structured output. NOTE: Anthropic's structured-output
     * schema rejects `minimum`/`maximum` on `number` (400 invalid_request_error),
     * so the 0..1 range lives in the descriptions + prompt and is enforced by
     * clamp() on the way out — do not re-add min/max here.
     *
     * @var array<string, mixed>
     */
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'match_confidence' => ['type' => 'number', 'description' => 'Confidence from 0 to 1 that the evidence is the TARGET company.'],
            'data_coverage' => ['type' => 'number', 'description' => 'How complete the profile info is, from 0 to 1.'],
            'reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['match_confidence', 'data_coverage', 'reasons'],
        'additionalProperties' => false,
    ];

    public function __construct(private LlmClient $llm)
    {
    }

    /**
     * @param array{name: string, code?: ?string, city?: ?string, address?: ?string, legal_form?: ?string, country?: ?string} $targetFacts
     * @param array{domain?: ?string, content?: ?string, profile?: ?array<string, mixed>} $evidence
     *
     * @return array{verdict: string, verified: bool, match_confidence: ?float, data_coverage: ?float, reasons: array<int, string>}
     */
    public function judge(array $targetFacts, array $evidence): array
    {
        if (!config('react.match_judge.enabled', true)) {
            return $this->failOpen('match judge disabled');
        }

        try {
            $raw = $this->llm->structured($this->messages($targetFacts, $evidence), self::SCHEMA);

            return $this->normalize($raw);
        } catch (\Throwable $e) {
            Log::warning('CompanyMatchJudge failed (fail-open)', ['error' => $e->getMessage()]);

            return $this->failOpen('judge error: ' . $e->getMessage());
        }
    }

    /**
     * Whether a judged result should be rejected outright (used by the domain
     * resolution gate). Only a definite "fail" rejects — warn/unknown proceed.
     *
     * @param array{verdict: string} $result
     */
    public function rejects(array $result): bool
    {
        return ($result['verdict'] ?? 'warn') === 'fail';
    }

    /**
     * @param array{name: string, code?: ?string, city?: ?string, address?: ?string, legal_form?: ?string, country?: ?string} $targetFacts
     * @param array{domain?: ?string, content?: ?string, profile?: ?array<string, mixed>} $evidence
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function messages(array $targetFacts, array $evidence): array
    {
        $system = <<<'TXT'
        You verify whether a scraped website (or an extracted company profile) belongs to a
        specific TARGET company, and rate how completely it describes that company.

        The target is typically a Lithuanian (LT) company; website/profile content may be in
        Lithuanian OR English — both are acceptable, do not penalise language.

        Judge match by concrete evidence, not by name alone: a name match is NOT enough when
        the location, country, activity or contacts clearly point to a different, same-named
        company (e.g. a US firm when the target is a Lithuanian one) — that is a mismatch.

        Return:
        - match_confidence (0..1): how sure you are the evidence is the TARGET company.
        - data_coverage (0..1): how much useful profile info (description, activity, contacts,
          address, social links) the evidence actually contains.
        - reasons: short bullet strings citing the concrete signals behind your scores.
        TXT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $this->renderTarget($targetFacts) . "\n\n" . $this->renderEvidence($evidence)],
        ];
    }

    /**
     * @param array{name: string, code?: ?string, city?: ?string, address?: ?string, legal_form?: ?string, country?: ?string} $targetFacts
     */
    private function renderTarget(array $targetFacts): string
    {
        $lines = ['TARGET COMPANY:'];
        $lines[] = '- name: ' . ($targetFacts['name'] ?? '(unknown)');
        foreach (['code' => 'registry code', 'legal_form' => 'legal form', 'city' => 'city', 'address' => 'address'] as $key => $label) {
            $value = trim((string) ($targetFacts[$key] ?? ''));
            if ($value !== '') {
                $lines[] = "- {$label}: {$value}";
            }
        }
        $lines[] = '- country: ' . (trim((string) ($targetFacts['country'] ?? '')) ?: 'LT (Lithuania)');

        return implode("\n", $lines);
    }

    /**
     * @param array{domain?: ?string, content?: ?string, profile?: ?array<string, mixed>} $evidence
     */
    private function renderEvidence(array $evidence): string
    {
        $lines = ['EVIDENCE:'];
        if (!empty($evidence['domain'])) {
            $lines[] = '- resolved domain: ' . $evidence['domain'];
        }
        if (!empty($evidence['profile'])) {
            $lines[] = '- extracted profile: ' . json_encode($evidence['profile'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $content = trim((string) ($evidence['content'] ?? ''));
        if ($content !== '') {
            // Cap the raw page content so the judge prompt stays bounded.
            $lines[] = "- page content:\n" . mb_substr($content, 0, 6000);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{verdict: string, verified: bool, match_confidence: ?float, data_coverage: ?float, reasons: array<int, string>}
     */
    private function normalize(array $raw): array
    {
        $confidence = $this->clamp($raw['match_confidence'] ?? null);
        $coverage = $this->clamp($raw['data_coverage'] ?? null);

        $reasons = [];
        foreach ((array) ($raw['reasons'] ?? []) as $reason) {
            if (is_string($reason) && trim($reason) !== '') {
                $reasons[] = trim($reason);
            }
        }

        return [
            'verdict' => $this->verdictFor($confidence),
            // A model answer whose match_confidence is not a number leaves verdictFor()
            // nothing to derive from, so it lands on the same 'warn' as the fail-open
            // below while no judgement was actually reached. Saying so here is what lets
            // the collector record that acceptance as unverified instead of as a verdict.
            'verified' => $confidence !== null,
            'match_confidence' => $confidence,
            'data_coverage' => $coverage,
            'reasons' => $reasons,
        ];
    }

    private function clamp(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function verdictFor(?float $confidence): string
    {
        if ($confidence === null) {
            return 'warn';
        }

        if ($confidence >= (float) config('react.match_judge.pass_threshold', 0.7)) {
            return 'pass';
        }

        if ($confidence >= (float) config('react.match_judge.fail_threshold', 0.4)) {
            return 'warn';
        }

        return 'fail';
    }

    /**
     * The non-blocking verdict returned when the judge could not judge. `verified: false`
     * is the whole point: the verdict word is 'warn' so callers keep their existing
     * accept/reject behaviour (a judge outage must never abort a run), while the flag lets
     * an audit trail tell this apart from a warn the model actually reasoned its way to.
     *
     * @return array{verdict: string, verified: bool, match_confidence: ?float, data_coverage: ?float, reasons: array<int, string>}
     */
    private function failOpen(string $reason): array
    {
        return [
            'verdict' => 'warn',
            'verified' => false,
            'match_confidence' => null,
            'data_coverage' => null,
            'reasons' => [$reason],
        ];
    }
}
