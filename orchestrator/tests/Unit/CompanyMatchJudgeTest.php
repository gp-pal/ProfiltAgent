<?php

namespace Tests\Unit;

use App\IO\Contracts\LlmClient;
use App\Services\CompanyMatchJudge;
use Mockery;
use Tests\TestCase;

class CompanyMatchJudgeTest extends TestCase
{
    private $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = Mockery::mock(LlmClient::class);
        config()->set('react.match_judge.enabled', true);
        config()->set('react.match_judge.pass_threshold', 0.7);
        config()->set('react.match_judge.fail_threshold', 0.4);
    }

    private function judge(): CompanyMatchJudge
    {
        return new CompanyMatchJudge($this->llm);
    }

    public function test_high_confidence_is_pass(): void
    {
        $this->llm->shouldReceive('structured')->once()->andReturn([
            'match_confidence' => 0.92,
            'data_coverage' => 0.8,
            'reasons' => ['LT address matches', 'activity matches registry'],
        ]);

        $result = $this->judge()->judge(['name' => 'Acmena', 'city' => 'Vilnius'], ['domain' => 'acmena.lt']);

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame(0.92, $result['match_confidence']);
        $this->assertSame(0.8, $result['data_coverage']);
        $this->assertFalse($this->judge()->rejects($result));
    }

    public function test_mid_confidence_is_warn(): void
    {
        $this->llm->shouldReceive('structured')->once()->andReturn([
            'match_confidence' => 0.55,
            'data_coverage' => 0.3,
            'reasons' => ['name matches but location unclear'],
        ]);

        $result = $this->judge()->judge(['name' => 'Acmena'], ['domain' => 'acmena.com']);

        $this->assertSame('warn', $result['verdict']);
        $this->assertFalse($this->judge()->rejects($result));
    }

    public function test_low_confidence_is_fail_and_rejected(): void
    {
        // Pergola -> US: name matches, location is Maryland -> mismatch.
        $this->llm->shouldReceive('structured')->once()->andReturn([
            'match_confidence' => 0.1,
            'data_coverage' => 0.7,
            'reasons' => ['address is Havre De Grace, Maryland (US), target is Lithuanian'],
        ]);

        $result = $this->judge()->judge(
            ['name' => 'Pergola', 'code' => '300000789', 'country' => 'LT'],
            ['domain' => 'pergola.com', 'profile' => ['description' => 'cast stone US']]
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertTrue($this->judge()->rejects($result));
    }

    public function test_fails_open_to_warn_on_llm_error(): void
    {
        $this->llm->shouldReceive('structured')->once()->andThrow(new \RuntimeException('LLM down'));

        $result = $this->judge()->judge(['name' => 'Acmena'], ['domain' => 'acmena.lt']);

        $this->assertSame('warn', $result['verdict']);
        $this->assertNull($result['match_confidence']);
        $this->assertFalse($this->judge()->rejects($result), 'a judge outage must never reject a candidate');
    }

    public function test_disabled_skips_llm_and_warns(): void
    {
        config()->set('react.match_judge.enabled', false);
        $this->llm->shouldNotReceive('structured');

        $result = $this->judge()->judge(['name' => 'Acmena'], ['domain' => 'acmena.lt']);

        $this->assertSame('warn', $result['verdict']);
    }

    /**
     * The fail-open keeps the word 'warn' so no caller's accept/reject behaviour changes,
     * which means the word alone cannot tell a judge outage from a judged maybe. `verified`
     * is what does, and it is the only thing standing between an LLM outage and a stored run
     * that looks like it was checked.
     */
    public function test_a_fail_open_declares_itself_unverified(): void
    {
        $this->llm->shouldReceive('structured')->once()->andThrow(new \RuntimeException('LLM down'));

        $failedOpen = $this->judge()->judge(['name' => 'Acmena'], ['domain' => 'acmena.lt']);

        $this->assertSame('warn', $failedOpen['verdict']);
        $this->assertFalse($failedOpen['verified'], 'a judge outage must not report itself as a verdict');
    }

    public function test_a_disabled_judge_declares_itself_unverified(): void
    {
        config()->set('react.match_judge.enabled', false);
        $this->llm->shouldNotReceive('structured');

        $this->assertFalse($this->judge()->judge(['name' => 'Acmena'], ['domain' => 'acmena.lt'])['verified']);
    }

    public function test_a_judged_warn_declares_itself_verified(): void
    {
        $this->llm->shouldReceive('structured')->once()->andReturn([
            'match_confidence' => 0.55,
            'data_coverage' => 0.3,
            'reasons' => ['name matches but location unclear'],
        ]);

        $judged = $this->judge()->judge(['name' => 'Acmena'], ['domain' => 'acmena.com']);

        $this->assertSame('warn', $judged['verdict']);
        $this->assertTrue($judged['verified'], 'a warn the model reasoned to IS a verdict');
    }

    /**
     * verdictFor() has nothing to derive from when match_confidence is not a number, so the
     * answer lands on 'warn' without any judgement having been reached — the fail-open's
     * shape, arrived at from the other side.
     */
    public function test_an_unusable_confidence_is_not_a_verdict(): void
    {
        $this->llm->shouldReceive('structured')->once()->andReturn([
            'match_confidence' => 'very sure',
            'data_coverage' => 0.5,
            'reasons' => ['looks right'],
        ]);

        $result = $this->judge()->judge(['name' => 'Acmena'], ['domain' => 'acmena.lt']);

        $this->assertSame('warn', $result['verdict']);
        $this->assertFalse($result['verified']);
    }

    public function test_clamps_out_of_range_and_filters_blank_reasons(): void
    {
        $this->llm->shouldReceive('structured')->once()->andReturn([
            'match_confidence' => 1.5,
            'data_coverage' => -0.2,
            'reasons' => ['ok', '', '  ', 42],
        ]);

        $result = $this->judge()->judge(['name' => 'Acmena'], ['domain' => 'acmena.lt']);

        $this->assertSame(1.0, $result['match_confidence']);
        $this->assertSame(0.0, $result['data_coverage']);
        $this->assertSame(['ok'], $result['reasons']);
    }
}
