<?php

namespace App\Services;

/**
 * The company's own website as a source: wraps the existing Step-1/ReAct pipeline
 * (resolve domain -> capture -> profile), exposing its built profile as collected
 * fields plus meta (resolved domain, match verdict). This is where description,
 * activity, contacts and social links usually come from.
 */
class OfficialSiteSource implements CollectionSource
{
    public function __construct(private ReActLoopService $react)
    {
    }

    public function name(): string
    {
        return 'official_site';
    }

    public function supports(array $target): bool
    {
        return trim((string) ($target['name'] ?? '')) !== '';
    }

    public function collect(array $target): CollectionResult
    {
        $name = (string) ($target['name'] ?? '');
        $domain = $target['domain'] ?? null;
        $session = (string) ($target['session'] ?? ('src-' . md5($name)));

        $res = $this->react->runStep1Bootstrap($name, $domain, $session, [
            'targetFacts' => $target,
            'declared_fields' => $target['declared_fields'] ?? [],
        ]);

        $profile = $res['profile'] ?? null;
        if (!is_array($profile)) {
            // A resolved-but-failed capture is a recoverable 'error'; a firm with
            // no site at all (no domain resolved) is a clean 'empty' — nothing to
            // collect, not a retryable failure — so the collector's saturation
            // check doesn't mistake a website-less firm for a broken run.
            $status = (($res['domain'] ?? null) !== null && ($res['error'] ?? null)) ? 'error' : 'empty';

            return CollectionResult::none($this->name(), $status, [
                'domain' => $res['domain'] ?? null,
                'error' => $res['error'] ?? null,
            ]);
        }

        $fields = [];
        // `?:` not `??`: an empty declared list must fall back too. FieldRequirements
        // no longer produces one, but here it would silently drop the whole profile
        // instead of degrading to the legacy six like every other consumer does.
        $declared = ($target['declared_fields'] ?? []) ?: ProfileCoverageScorer::FIELDS;
        foreach ($declared as $field) {
            $value = $profile[$field] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                $fields[$field] = $value;
            }
        }

        return new CollectionResult(
            $this->name(),
            $fields === [] ? 'empty' : 'ok',
            $fields,
            [
                'domain' => $res['domain'] ?? null,
                'resolved_via' => $res['resolved_via'] ?? null,
                'quality' => $profile['quality'] ?? null,
            ],
        );
    }
}
