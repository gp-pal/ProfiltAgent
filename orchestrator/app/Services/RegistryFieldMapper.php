<?php

namespace App\Services;

/**
 * Maps company registry facts onto the profile's catalogue field keys.
 *
 * Extracted from CompanyRegistrySource so the admin console's prefill and the collector's
 * registry source share one mapping. With a copy in each, the suggestions an operator
 * sees would drift from what the pipeline actually takes, and the page would quietly
 * stop describing the system it exists to test.
 *
 * A key is only included when the source value is present, so a minimal facts row (just
 * an address) yields just that one field rather than empty strings the coverage scorer
 * would score as filled.
 */
class RegistryFieldMapper
{
    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function fields(array $facts): array
    {
        $fields = [];

        if (!empty($facts['activity'])) {
            $fields['activity'] = $facts['activity'];
        }
        if (!empty($facts['address'])) {
            $fields['address'] = $facts['address'];
        }

        if (!empty($facts['email'])) {
            $fields['emails'] = [$facts['email']];
        }

        $phones = array_values(array_unique(array_filter([
            $facts['phone'] ?? null,
            $facts['mobile'] ?? null,
        ])));
        if ($phones !== []) {
            $fields['phones'] = $phones;
        }

        $socialLinks = array_values(array_unique(array_filter([
            $facts['x'] ?? null,
            $facts['tiktok'] ?? null,
            $facts['skype'] ?? null,
        ])));
        if ($socialLinks !== []) {
            $fields['social_links'] = $socialLinks;
        }

        if (!empty($facts['about'])) {
            $fields['description'] = $facts['about'];
        }

        return $fields;
    }
}
