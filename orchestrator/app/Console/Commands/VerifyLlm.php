<?php

namespace App\Console\Commands;

use App\IO\Contracts\LlmClient;
use App\Services\CompanyProfileService;
use Illuminate\Console\Command;

/**
 * Live end-to-end check of the configured LLM provider.
 *
 * Unit tests mock the LLM, so they cannot prove the real provider/key works.
 * This command makes two real calls — a structured() probe and a full company
 * profile build from sample HTML — and fails loudly if the provider/key/quota
 * is not actually working. Run it locally where a real API key is present.
 */
class VerifyLlm extends Command
{
    protected $signature = 'agent:verify-llm';

    protected $description = 'Live check of the LLM provider (structured() + profile build). Needs a real API key with quota.';

    public function __construct(
        private LlmClient $llm,
        private CompanyProfileService $profileService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Provider: ' . config('llm.provider') . ' | model: ' . config('llm.model'));

        $this->line("\n[1/2] structured() probe...");
        try {
            $out = $this->llm->structured(
                [
                    ['role' => 'system', 'content' => 'Return JSON only.'],
                    ['role' => 'user', 'content' => 'Set answer to the single word PONG.'],
                ],
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['answer' => ['type' => 'string']],
                    'required' => ['answer'],
                ],
            );
        } catch (\Throwable $e) {
            $this->error('  ✗ structured() FAILED: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (($out['answer'] ?? null) === null) {
            $this->error('  ✗ structured() returned no "answer": ' . json_encode($out));

            return self::FAILURE;
        }
        $this->info('  ✓ structured() OK → answer=' . $out['answer']);

        $this->line("\n[2/2] company profile build...");
        $sampleHtml = <<<'HTML'
        <html><head><title>Acmena UAB</title></head><body>
        <h1>Acmena UAB</h1>
        <p>Esame Vilniuje įsikūrusi statybos įmonė, teikianti pastatų renovacijos paslaugas.</p>
        <p>El. paštas: info@acmena.lt, tel. +370 600 00000. Adresas: Gedimino pr. 1, Vilnius.</p>
        <a href="https://www.facebook.com/acmena">Facebook</a>
        <a href="https://www.linkedin.com/company/acmena">LinkedIn</a>
        </body></html>
        HTML;

        $structure = ['company_indicators' => [
            'contact_info' => ['emails' => ['info@acmena.lt'], 'phones' => ['+370 600 00000']],
            'social_links' => ['https://www.facebook.com/acmena', 'https://www.linkedin.com/company/acmena'],
        ]];

        try {
            $profile = $this->profileService->build('Acmena', 'acmena.lt', $sampleHtml, $structure);
        } catch (\Throwable $e) {
            $this->error('  ✗ profile build FAILED: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('  ✓ profile built:');
        $this->line('    name:     ' . ($profile['name'] ?? ''));
        $this->line('    activity: ' . ($profile['activity'] ?? ''));
        $this->line('    address:  ' . ($profile['address'] ?? ''));
        $this->line('    emails:   ' . implode(', ', $profile['emails'] ?? []));
        $this->line('    social:   ' . implode(', ', $profile['social_links'] ?? []));

        // The profile should reflect the LLM actually reading the page. If both
        // description and activity are empty, the structured call likely fell
        // back (e.g. quota) and only the deterministic contacts survived.
        if (trim((string) ($profile['description'] ?? '')) === ''
            && trim((string) ($profile['activity'] ?? '')) === '') {
            $this->warn("\n⚠ No LLM-derived description/activity — the structured call may have fallen back. Check provider/quota.");

            return self::FAILURE;
        }

        $this->info("\n✅ LIVE VERIFICATION PASSED");

        return self::SUCCESS;
    }
}
