<?php

namespace App\Validation;

class PolicyChecks
{
    private int $maxSteps;
    private int $maxTimeout;
    private array $allowedDomains;
    private string $runsDir;
    private UrlGuard $urlGuard;

    public function __construct()
    {
        $this->maxSteps = env('MAX_STEPS', 30);
        $this->maxTimeout = 20000; // 20 seconds
        $this->allowedDomains = explode(',', env('ALLOWED_DOMAINS', ''));
        $this->runsDir = env('RUNS_DIR', 'runs');
        $this->urlGuard = new UrlGuard();
    }

    public function check(array $plan): bool
    {
        $this->checkStepLimit($plan);
        $this->checkTimeouts($plan);
        $this->checkDomainPolicy($plan);
        $this->checkArtifactPaths($plan);

        return true;
    }

    private function checkStepLimit(array $plan): void
    {
        if (count($plan['actions']) > $this->maxSteps) {
            throw new \InvalidArgumentException("Too many steps: " . count($plan['actions']) . " > {$this->maxSteps}");
        }
    }

    private function checkTimeouts(array $plan): void
    {
        foreach ($plan['actions'] as $i => $action) {
            if (isset($action['timeout_ms']) && $action['timeout_ms'] > $this->maxTimeout) {
                throw new \InvalidArgumentException("Step {$i}: timeout {$action['timeout_ms']}ms exceeds maximum {$this->maxTimeout}ms");
            }
        }
    }

    private function checkDomainPolicy(array $plan): void
    {
        $domains = [];

        foreach ($plan['actions'] as $i => $action) {
            if ($action['do'] === 'goto') {
                $url = $action['url'];
                $domain = parse_url($url, PHP_URL_HOST);

                if (!$domain) {
                    throw new \InvalidArgumentException("Step {$i}: invalid URL format: {$url}");
                }

                $this->assertUrlIsSafe($url, $i);

                $registeredDomain = $this->getRegisteredDomain($domain);
                $domains[$registeredDomain] = true;
            }
        }

        // Check if multiple registered domains are used (not allowed unless whitelisted)
        if (count($domains) > 1) {
            $domainList = array_keys($domains);

            // Check if all domains are in allowed list
            foreach ($domainList as $domain) {
                if (!in_array($domain, $this->allowedDomains) && !empty($this->allowedDomains)) {
                    throw new \InvalidArgumentException("Cross-domain navigation not allowed: " . implode(', ', $domainList));
                }
            }
        }
    }

    private function checkArtifactPaths(array $plan): void
    {
        foreach ($plan['actions'] as $i => $action) {
            if (isset($action['path'])) {
                $path = $action['path'];

                // Must be within runs/ directory
                if (!str_starts_with($path, 'runs/')) {
                    throw new \InvalidArgumentException("Step {$i}: artifact path must be within runs/ directory: {$path}");
                }

                // No directory traversal
                if (str_contains($path, '..')) {
                    throw new \InvalidArgumentException("Step {$i}: directory traversal not allowed in path: {$path}");
                }

                // No absolute paths
                if (str_starts_with($path, '/')) {
                    throw new \InvalidArgumentException("Step {$i}: absolute paths not allowed: {$path}");
                }
            }
        }
    }

    private function assertUrlIsSafe(string $url, int $step): void
    {
        try {
            $this->urlGuard->assertSafe($url);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException("Step {$step}: " . $e->getMessage());
        }
    }

    private function getRegisteredDomain(string $domain): string
    {
        // Simple registered domain extraction (for production, use a proper library)
        $parts = explode('.', $domain);

        if (count($parts) >= 2) {
            return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
        }

        return $domain;
    }

    public function checkSameDomain(array $urls): bool
    {
        if (empty($urls)) {
            return true;
        }

        $firstDomain = null;

        foreach ($urls as $url) {
            $domain = parse_url($url, PHP_URL_HOST);
            $registeredDomain = $this->getRegisteredDomain($domain);

            if ($firstDomain === null) {
                $firstDomain = $registeredDomain;
            } elseif ($firstDomain !== $registeredDomain) {
                return false;
            }
        }

        return true;
    }
}