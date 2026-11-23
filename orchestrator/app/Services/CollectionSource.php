<?php

namespace App\Services;

/**
 * A source of company information (registry DB, official site, open web, ...).
 * The collector calls supported sources in a legal-first order, targeting the
 * gaps the coverage scorer reports, and records each call in the run registry.
 */
interface CollectionSource
{
    /** Stable identifier recorded in the registry, e.g. 'registry_db'. */
    public function name(): string;

    /**
     * Whether this source can contribute anything for the given target.
     *
     * @param array<string, mixed> $target
     */
    public function supports(array $target): bool;

    /**
     * Collect whatever this source can for the target.
     *
     * @param array<string, mixed> $target
     */
    public function collect(array $target): CollectionResult;
}
