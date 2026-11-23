<?php

namespace App\Services;

use App\Tools\SessionFs;
use Symfony\Component\Yaml\Yaml;

class PromptBuilderService
{
    private array $policy;
    private string $dslSchemaExcerpt;
    private string $schemaHash;
    private string $schemaPath;
    private int $excerptBytes;
    private array $promptPaths;

    public function __construct(private SessionFs $sessionFs)
    {
        $this->loadPolicy();
        $this->loadDslSchema();
        $this->loadPromptPaths();
    }

    /**
     * Build Step-1 planning messages for company analysis
     *
     * @param string $company Company name
     * @param string|null $domain Domain or null if unknown
     * @param string $session Session ULID/UUID
     * @return array ['messages' => [...], 'trace' => [...]]
     */
    public function makeBootstrapPlanMessages(string $company, ?string $domain, string $session): array
    {
        $systemPrompt = $this->buildPlanSystemPrompt();
        $userPrompt = $this->buildPlanUserPrompt($company, $domain, $session);

        return [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'trace' => $this->getTrace()
        ];
    }

    /**
     * Build one-shot repair messages
     *
     * @param string $errorsJson JSON string of validation errors
     * @param string $lastPlanJson JSON string of the last plan
     * @return array ['messages' => [...]]
     */
    public function makeRepairMessages(string $errorsJson, string $lastPlanJson): array
    {
        $systemPrompt = $this->buildPlanSystemPrompt();
        $userPrompt = $this->buildRepairUserPrompt($errorsJson, $lastPlanJson);

        return [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ]
        ];
    }

    /**
     * Legacy methods for backwards compatibility
     */
    public function planSystem(): string
    {
        return $this->buildPlanSystemPrompt();
    }

    public function planUser(array $company): string
    {
        $companyName = $company['name'] ?? 'Unknown Company';
        $domain = $company['domain'] ?? null;
        $session = $company['session'] ?? 'unknown';

        return $this->buildPlanUserPrompt($companyName, $domain, $session);
    }

    public function repairUser(string $errorsJson, string $lastPlan): string
    {
        return $this->buildRepairUserPrompt($errorsJson, $lastPlan);
    }

    private function loadPolicy(): void
    {
        $policyPath = base_path('resources/policy.yaml');

        if (!file_exists($policyPath)) {
            // Try alternative location
            $policyPath = base_path('../configs/policy.yaml');
        }

        if (!file_exists($policyPath)) {
            throw new \RuntimeException("Policy file not found. Expected at resources/policy.yaml or ../configs/policy.yaml");
        }

        try {
            $rawPolicy = Yaml::parseFile($policyPath);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to parse policy YAML: " . $e->getMessage());
        }

        // Handle both nested (profiltagent_policy.*) and flat policy layouts
        if (isset($rawPolicy['profiltagent_policy'])) {
            $this->policy = $rawPolicy['profiltagent_policy'];
        } else {
            $this->policy = $rawPolicy;
        }

        // Validate required policy fields
        $this->validatePolicyStructure();
    }

    private function validatePolicyStructure(): void
    {
        $required = [
            'limits.max_steps',
            'limits.max_timeout_ms',
            'security.artifact_restrictions.base_path'
        ];

        foreach ($required as $path) {
            $value = $this->getPolicyValue($path);
            if ($value === null) {
                throw new \RuntimeException("Required policy field missing: {$path}");
            }
        }
    }

    private function getPolicyValue(string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $this->policy;

        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private function loadDslSchema(): void
    {
        $this->schemaPath = base_path('resources/dsl/schema.json');

        if (!file_exists($this->schemaPath)) {
            throw new \RuntimeException("DSL schema not found at: {$this->schemaPath}");
        }

        $schemaContent = file_get_contents($this->schemaPath);
        if ($schemaContent === false) {
            throw new \RuntimeException("Failed to read DSL schema file");
        }

        // Validate JSON
        json_decode($schemaContent);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in DSL schema: " . json_last_error_msg());
        }

        // Compute SHA-256 hash
        $this->schemaHash = hash('sha256', $schemaContent);

        // Create normalized excerpt (~2000 chars)
        $normalizedContent = $this->normalizeContent($schemaContent);
        $this->excerptBytes = min(2000, strlen($normalizedContent));
        $this->dslSchemaExcerpt = substr($normalizedContent, 0, $this->excerptBytes);

        if (strlen($normalizedContent) > 2000) {
            $this->dslSchemaExcerpt .= '...';
        }
    }

    private function loadPromptPaths(): void
    {
        $this->promptPaths = [
            'system_plan' => resource_path('prompts/bootstrapping/system_plan.txt'),
            'user_plan' => resource_path('prompts/bootstrapping/user_plan.txt'),
            'user_repair' => resource_path('prompts/user_repair.txt'),
        ];

        // Validate required prompt files
        $required = ['system_plan', 'user_plan', 'user_repair'];
        foreach ($required as $key) {
            if (!file_exists($this->promptPaths[$key])) {
                throw new \RuntimeException("Required prompt file not found: {$this->promptPaths[$key]}");
            }
        }
    }

    private function buildPlanSystemPrompt(): string
    {
        $template = file_get_contents($this->promptPaths['system_plan']);

        return $this->replacePlaceholders($template, [
            '{{policy.limits.max_steps}}' => $this->getPolicyValue('limits.max_steps'),
            '{{policy.limits.max_timeout_ms}}' => $this->getPolicyValue('limits.max_timeout_ms'),
            '{{policy.security.artifact_restrictions.base_path}}' => rtrim($this->getPolicyValue('security.artifact_restrictions.base_path'), '/'),
            '{{policy.security.runs_dir}}' => rtrim($this->getPolicyValue('security.artifact_restrictions.base_path'), '/'),
            '{{dsl_schema_excerpt}}' => $this->dslSchemaExcerpt,
            '{{dsl_excerpt}}' => $this->dslSchemaExcerpt,
            '{{schema_sha256}}' => $this->schemaHash,
            '{{about_synonyms_lt}}' => 'apie, apie mus, kas mes esame, įmonė',
            '{{about_synonyms_en}}' => 'about, about us, who we are, company'
        ]);
    }

    private function buildPlanUserPrompt(string $company, ?string $domain, string $session): string
    {
        $template = file_get_contents($this->promptPaths['user_plan']);

        // Screenshot is the proof of what the agent saw; the runner writes it into
        // the session's screenshots dir (shared volume) where isStep1Satisfied looks.
        $analysisPath = $domain
            ? $this->sessionFs->getAnalysisPath($session, $domain)
            : "runs/{$session}/pending/analyze";
        $screenshotPath = "{$analysisPath}/screenshots/home.png";

        return $this->replacePlaceholders($template, [
            '{{company.name}}' => $company,
            '{{company.domain}}' => $domain ?? 'null',
            '{{session}}' => $session,
            '{{screenshot_path}}' => $screenshotPath,
            '{{runs_dir}}' => rtrim($this->getPolicyValue('security.artifact_restrictions.base_path'), '/'),
            '{{policy.security.runs_dir}}' => rtrim($this->getPolicyValue('security.artifact_restrictions.base_path'), '/'),
            '{{policy.security.artifact_restrictions.base_path}}' => rtrim($this->getPolicyValue('security.artifact_restrictions.base_path'), '/')
        ]);
    }

    private function buildRepairUserPrompt(string $errorsJson, string $lastPlanJson): string
    {
        $template = file_get_contents($this->promptPaths['user_repair']);

        // Clip last plan to reasonable size for token efficiency
        $clippedPlan = strlen($lastPlanJson) > 1500 ?
            substr($lastPlanJson, 0, 1500) . '...' :
            $lastPlanJson;

        return $this->replacePlaceholders($template, [
            '{{errors_json}}' => $errorsJson,
            '{{last_plan}}' => $clippedPlan
        ]);
    }

    private function replacePlaceholders(string $template, array $replacements): string
    {
        $content = $template;

        foreach ($replacements as $placeholder => $value) {
            $content = str_replace($placeholder, (string)$value, $content);
        }

        return $this->normalizeContent($content);
    }

    private function normalizeContent(string $content): string
    {
        // Normalize line endings and whitespace for cross-platform stability
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/\n\s*\n\s*\n/', "\n\n", $content); // Max 2 consecutive newlines

        return trim($content);
    }

    public function getTrace(): array
    {
        return [
            'policy_caps' => [
                'max_steps' => $this->getPolicyValue('limits.max_steps'),
                'max_timeout_ms' => $this->getPolicyValue('limits.max_timeout_ms'),
                'runs_dir' => rtrim($this->getPolicyValue('security.artifact_restrictions.base_path'), '/')
            ],
            'schema' => [
                'sha256' => $this->schemaHash,
                'excerpt_bytes' => $this->excerptBytes,
                'path' => $this->schemaPath
            ],
            'prompts' => $this->promptPaths
        ];
    }
}