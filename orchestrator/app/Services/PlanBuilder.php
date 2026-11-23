<?php

namespace App\Services;

use App\IO\Contracts\LlmClient;
use App\Tools\SessionFs;
use App\Validation\PolicyChecks;
use App\Validation\SchemaValidator;
use Illuminate\Support\Facades\Log;

/**
 * PlanBuilder - Step-1 DSL plan generation and validation.
 *
 * Extracted from ReActLoopService: renders the fixed capture template, guards
 * against guardrail drift (a stray `search` action), and validates the plan
 * with a one-shot LLM repair attempt.
 */
class PlanBuilder
{
    public function __construct(
        private PromptBuilderService $promptBuilder,
        private LlmClient $ollamaClient,
        private SchemaValidator $schemaValidator,
        private PolicyChecks $policyChecks,
        private SessionFs $sessionFs,
    ) {
    }

    /**
     * Phase 3: PLAN_BUILD - Generate minimal homepage capture plan
     */
    public function build(array &$state): void
    {
        try {
            $promptResult = $this->promptBuilder->makeBootstrapPlanMessages(
                $state['company'],
                $state['domain'],
                $state['session']
            );

            $messages = $promptResult['messages'];
            $state['trace'] = $promptResult['trace'];

            Log::debug('Bootstrap: Sending prompts to LLM', [
                'session' => $state['session'],
                'company' => $state['company'],
                'domain' => $state['domain'],
                'system_prompt_length' => strlen($messages[0]['content'] ?? ''),
                'user_prompt_length' => strlen($messages[1]['content'] ?? ''),
                'user_prompt' => substr($messages[1]['content'] ?? '', 0, 300)
            ]);

            if ($state['thinking']) {
                $state['events'][] = ['phase' => 'plan.prompts_ready'];
            }

            // The Step-1 plan is a fixed, deterministic template (user_plan.txt)
            // with the domain/session already filled in. Routing it through the
            // LLM only adds non-determinism (e.g. inventing schema-invalid
            // properties like `same_origin_only`), so parse the template directly.
            // The LLM is reserved for profile extraction, where it adds value.
            $planContent = $messages[1]['content'] ?? '';

            $plan = json_decode(trim($planContent), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Bootstrap: plan template JSON parsing failed', [
                    'session' => $state['session'],
                    'json_error' => json_last_error_msg(),
                    'content' => substr($planContent, 0, 500),
                ]);
                $state['error'] = 'validation_failed';
                $state['details'] = 'Invalid Step-1 plan template JSON: ' . json_last_error_msg();
                return;
            }

            // Clean up plan to match schema requirements
            Log::debug("Plan structure before cleanup", [
                'session' => $state['session'],
                'meta_keys' => array_keys($plan['meta'] ?? []),
                'has_session_field' => isset($plan['meta']['session'])
            ]);

            if (isset($plan['meta']['session'])) {
                unset($plan['meta']['session']);
                Log::debug("Removed session field from meta to match schema", [
                    'session' => $state['session']
                ]);
            } else {
                Log::debug("No session field found in meta", [
                    'session' => $state['session'],
                    'meta_structure' => $plan['meta'] ?? 'missing'
                ]);
            }

            // Remove extra properties that LLM sometimes adds (not part of DSL schema)
            $extraProperties = ['results', 'result', 'response', 'output'];
            foreach ($extraProperties as $prop) {
                if (isset($plan[$prop])) {
                    unset($plan[$prop]);
                    Log::debug("Removed extra property '$prop' from plan to match schema", [
                        'session' => $state['session']
                    ]);
                }
            }

            // Handle search action (should not happen with domain resolved)
            if (isset($plan['action']) && $plan['action'] === 'search') {
                Log::warning('Bootstrap: LLM returned search action despite having domain', [
                    'session' => $state['session'],
                    'domain' => $state['domain'],
                    'plan' => $plan
                ]);
                // Guardrail drift - rebuild with domain enforced
                if ($state['thinking']) {
                    $state['events'][] = ['phase' => 'plan.guardrail_drift'];
                }

                // Retry once with domain enforced
                $llmResponse = $this->ollamaClient->withFallback([
                    'messages' => $messages, // Same messages should work since domain is now set
                    'temperature' => 0.1,
                    'max_tokens' => 2000
                ]);

                $planContent = $llmResponse['choices'][0]['message']['content'];
                $planContent = PlanJsonExtractor::extract($planContent);
                $plan = json_decode(trim($planContent), true);

                if (json_last_error() !== JSON_ERROR_NONE || (isset($plan['action']) && $plan['action'] === 'search')) {
                    $state['error'] = 'validation_failed';
                    $state['details'] = 'LLM continued to return search action despite having domain';
                    return;
                }
            }

            // Add session and domain to plan meta
            $plan['meta'] = array_merge($plan['meta'] ?? [], [
                'domain' => $state['domain']
            ]);

            $state['plan'] = $plan;

            // Persist initial plan
            $this->sessionFs->writePlan($state['paths']['root'], 'initial.json', $plan);

            if ($state['thinking']) {
                $state['events'][] = ['phase' => 'plan.built'];
            }

            Log::info("Plan built for session {$state['session']}", [
                'company' => $state['company'],
                'domain' => $state['domain'],
                'actions_count' => count($plan['actions'] ?? [])
            ]);

        } catch (\Exception $e) {
            $state['error'] = 'internal';
            $state['details'] = 'Plan generation failed: ' . $e->getMessage();
            Log::error("Plan generation failed", [
                'session' => $state['session'],
                'company' => $state['company'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Phase 4: PLAN_VALIDATE - Validate plan with one repair attempt
     */
    public function validate(array &$state): void
    {
        $plan = $state['plan'];
        $repaired = false;

        try {
            // First validation attempt
            Log::debug("Validating plan", [
                'session' => $state['session'],
                'actions_count' => count($plan['actions'] ?? []),
                'plan_structure' => array_keys($plan)
            ]);

            $this->schemaValidator->validate($plan);
            $this->policyChecks->check($plan);

        } catch (\Exception $e) {
            Log::warning("Plan validation failed", [
                'session' => $state['session'],
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'plan_summary' => [
                    'version' => $plan['version'] ?? 'missing',
                    'meta' => isset($plan['meta']) ? 'present' : 'missing',
                    'actions_count' => count($plan['actions'] ?? [])
                ]
            ]);
            // One-shot repair attempt
            try {
                $repairResult = $this->promptBuilder->makeRepairMessages(
                    json_encode(['errors' => [$e->getMessage()]]),
                    json_encode($plan, JSON_PRETTY_PRINT)
                );

                $llmResponse = $this->ollamaClient->withFallback([
                    'messages' => $repairResult['messages'],
                    'temperature' => 0.2,
                    'max_tokens' => 2000
                ]);

                $repairedContent = $llmResponse['choices'][0]['message']['content'];
                $repairedContent = PlanJsonExtractor::extract($repairedContent);
                $repairedPlan = json_decode(trim($repairedContent), true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Repaired plan is not valid JSON');
                }

                // Add session and domain back to repaired plan meta
                $repairedPlan['meta'] = array_merge($repairedPlan['meta'] ?? [], [
                    'session' => $state['session'],
                    'domain' => $state['domain']
                ]);

                // Validate repaired plan
                $this->schemaValidator->validate($repairedPlan);
                $this->policyChecks->check($repairedPlan);

                // Repair successful
                $state['plan'] = $repairedPlan;
                $repaired = true;

                // Persist repaired plan
                $this->sessionFs->writePlan($state['paths']['root'], 'repair-1.json', $repairedPlan);

                Log::info("Plan repaired successfully for session {$state['session']}");

            } catch (\Exception $repairError) {
                $state['error'] = 'validation_failed';
                $state['details'] = [
                    'initial_error' => $e->getMessage(),
                    'repair_error' => $repairError->getMessage()
                ];
                return;
            }
        }

        if ($state['thinking']) {
            $state['events'][] = ['phase' => 'plan.validated', 'repaired' => $repaired];
        }

        Log::info("Plan validated for session {$state['session']}", [
            'repaired' => $repaired
        ]);
    }
}
