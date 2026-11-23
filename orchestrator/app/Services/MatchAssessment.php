<?php

namespace App\Services;

/**
 * The company-match decision for ONE source's contribution in ONE run: whether it was
 * accepted as the target company, and — the point of this class — the verdict that
 * decision actually rested on.
 *
 * It exists because the decision used to be a bare bool. `CollectorService::accepted()`
 * called the judge inline and threw the verdict away, so a stored run could not answer
 * whether `open_web` was accepted on a real `pass` or on the judge's fail-open `warn`;
 * one run stored a parent group's website as a subsidiary's social link on a verdict
 * nobody could inspect. Carrying both halves in one value makes an acceptance without
 * its verdict unrepresentable rather than merely discouraged.
 *
 * Two rules the vocabulary encodes:
 *
 * - `verdict` is the judge's own `pass`/`warn`/`fail` ONLY when a judge (or an
 *   authoritative key) really produced it. When nothing produced it, the verdict is
 *   {@see self::VERDICT_UNVERIFIED} — never `warn`. A missing verdict may still accept
 *   (see {@see CollectorService::assessMatch()}: the fail-open behaviour is deliberate
 *   and unchanged), but it must never be RECORDED as though a judge had looked.
 * - `basis` says which mechanism reached the verdict, so `pass` on an authoritative key
 *   cannot be misread as an LLM opinion, and an unverified acceptance names the reason
 *   it is unverified.
 * - `derived_from` names whose assertion a verdict rests on when it was not reached
 *   directly — today only `caller`, for values the caller supplied itself.
 */
final class MatchAssessment
{
    /** No verdict backed this decision: no judge answer at all, or one with nothing behind it. */
    public const VERDICT_UNVERIFIED = 'unverified';

    /** a registry row keyed by company code — identity is a fact, so no judge is asked. */
    public const BASIS_REGISTRY_CODE = 'registry_code';

    /** The judge was asked here, while gating this source, and answered. */
    public const BASIS_JUDGE = 'judge';

    /** The source carried a judge verdict of its own (`official_site`'s `meta['quality']`). */
    public const BASIS_STORED_VERDICT = 'stored_verdict';

    /** The caller asserted this identity itself (a supplied URL, or a supplied field value). */
    public const BASIS_CALLER_SUPPLIED = 'caller_supplied';

    /** Nothing produced a verdict; the acceptance rests on the fail-open default. */
    public const BASIS_NO_VERDICT = 'no_verdict';

    /** A verdict-shaped answer with nothing behind it: the judge's fail-open (LLM error or disabled). */
    public const BASIS_JUDGE_UNAVAILABLE = 'judge_unavailable';

    /**
     * @param array<int, string> $reasons
     * @param ?string $derivedFrom the source this verdict inherited from, when it was derived
     *                             rather than reached directly
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly string $verdict,
        public readonly string $basis,
        public readonly ?float $matchConfidence = null,
        public readonly ?float $dataCoverage = null,
        public readonly array $reasons = [],
        public readonly ?string $derivedFrom = null,
    ) {
    }

    /**
     * a registry row: the one source whose identity is an authoritative key rather than
     * a verdict. Recorded as `pass` because the identity IS established — by the company
     * code, which `basis` states outright.
     */
    public static function registryCode(): self
    {
        return new self(
            accepted: true,
            verdict: 'pass',
            basis: self::BASIS_REGISTRY_CODE,
            reasons: ['registry row keyed by company code'],
        );
    }

    /**
     * The caller's own assertion of identity: values the caller supplied as known_fields. Nothing here is derived from our own collection, so there is no
     * chain to break — the caller is the authority on data it already holds, exactly as
     * `known_fields` provenance is treated everywhere else.
     */
    public static function callerSupplied(string $what): self
    {
        return new self(
            accepted: true,
            verdict: 'pass',
            basis: self::BASIS_CALLER_SUPPLIED,
            reasons: ['identity asserted by the caller: ' . $what],
            derivedFrom: 'caller',
        );
    }

    /**
     * Whether a real match verdict stands behind this decision. False means the source was
     * accepted (or rejected) with no verification — the fail-open case a consumer must be
     * able to see.
     */
    public function verified(): bool
    {
        return $this->verdict !== self::VERDICT_UNVERIFIED;
    }

    /**
     * @return array{accepted: bool, verdict: string, basis: string, verified: bool, derived_from: ?string, match_confidence: ?float, data_coverage: ?float, reasons: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'verdict' => $this->verdict,
            'basis' => $this->basis,
            'verified' => $this->verified(),
            'derived_from' => $this->derivedFrom,
            'match_confidence' => $this->matchConfidence,
            'data_coverage' => $this->dataCoverage,
            'reasons' => $this->reasons,
        ];
    }
}
