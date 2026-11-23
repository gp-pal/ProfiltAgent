<?php

namespace App\Services;

use App\Tools\CompanyRegistryRepository;
use Illuminate\Support\Facades\Log;

/**
 * Legal, free baseline source: structural registry facts (activity, contacts,
 * social, address, size) from the registry DB by company code. Cheap to
 * query and always trustworthy for LT companies, so the collector starts here
 * before reaching to the web — for many gap firms this alone covers activity
 * and contacts, with the web only needed for a richer LT description.
 */
class CompanyRegistrySource implements CollectionSource
{
    public function __construct(
        private CompanyRegistryRepository $repo,
        private RegistryFieldMapper $mapper,
    ) {
    }

    public function name(): string
    {
        return 'registry_db';
    }

    public function supports(array $target): bool
    {
        // No registry configured is not a failure. Attempting the query anyway recorded
        // an `error` on every run, and an error marks the run retryable (`partial`) for
        // a source that no retry can ever reach.
        if (trim((string) config('company_registry.connection.host', '')) === '') {
            return false;
        }

        return trim((string) ($target['code'] ?? '')) !== ''
            || trim((string) ($target['name'] ?? '')) !== '';
    }

    public function collect(array $target): CollectionResult
    {
        $code = trim((string) ($target['code'] ?? ''));

        // No code supplied: resolve the firm in OUR registry by domain/name so we
        // pre-fill known fields (contacts, activity, …) instead of re-searching the
        // web for data we already own. Fail-safe: any lookup error -> skip.
        if ($code === '') {
            try {
                $code = (string) ($this->repo->resolveCode($target['domain'] ?? null, $target['name'] ?? null) ?? '');
            } catch (\Throwable $e) {
                Log::warning('CompanyRegistrySource resolve failed', ['error' => $e->getMessage()]);
                $code = '';
            }
        }

        if ($code === '') {
            return CollectionResult::none($this->name(), 'skipped', ['detail' => 'no registry code']);
        }

        try {
            $facts = $this->repo->factsForCode($code);
        } catch (\Throwable $e) {
            Log::warning('CompanyRegistrySource query failed', ['code' => $code, 'error' => $e->getMessage()]);

            return CollectionResult::none($this->name(), 'error', ['detail' => $e->getMessage()]);
        }

        if ($facts === null) {
            return CollectionResult::none($this->name(), 'empty', ['detail' => 'no registry row']);
        }

        $fields = $this->mapper->fields($facts);

        // The manager column carries at most one name; the manager tables carry the
        // rest. Their failure must not cost us the registry row we already have, so
        // this degrades to the single manager rather than erroring the whole source.
        try {
            $people = $this->repo->peopleForCode($code);
        } catch (\Throwable $e) {
            Log::warning('CompanyRegistrySource people lookup failed', ['code' => $code, 'error' => $e->getMessage()]);
            $people = [];
        }

        return new CollectionResult(
            $this->name(),
            $fields === [] ? 'empty' : 'ok',
            $fields,
            [
                'name' => $facts['name'],
                'employees' => $facts['employees'],
                'activity' => $facts['activity'] ?? null,
                'manager' => $facts['manager'] ?? null,
                'people' => $people,
                'vat_code' => $facts['vat_code'] ?? null,
                'registered' => $facts['registered'] ?? null,
            ],
        );
    }
}
