<?php

namespace App\Tools;

/**
 * The ONE ordering rule for the people the company registry names for a firm.
 *
 * The registry states its people in two places and neither is sufficient alone:
 * `firms.manager` (one person, and the only one whose ROLE the registry commits to,
 * because that column IS the manager field) and the manager tables behind
 * {@see CompanyRegistryRepository::peopleForCode()} (several people, usually role-less).
 *
 * Order is not cosmetic here, it IS the research budget. `react.news.max_queries` currently
 * leaves room for ONE person per run, and both {@see \App\Services\NewsQueryBuilder::build()}
 * and {@see \App\Services\CollectorService} keep the FIRST entry per name, so whoever this
 * method puts first is the only person actually researched and the one whose role survives.
 * The manager column therefore goes first: the person we know the role of keeps it, and the
 * single unit of person research is spent on the person the registry calls the boss.
 *
 * This class exists because that rule used to have two implementations. The collector built
 * the list from a source's meta (manager column first), while `/admin/request-console` built
 * its prefill straight from `peopleForCode()` and never consulted the manager column at all.
 * Running one firm through the console therefore researched a non-manager and stored every
 * person role-less, while running the same firm without prefill researched the CEO and kept
 * `Vadovas`. Both call sites now
 * delegate here; neither reimplements any part of it.
 */
final class RegistryPeople
{
    /**
     * Role assumed for the manager column when the value itself states none.
     *
     * Not a guess for `firms.manager`, and a fabrication for anything else — which is
     * why `$managerColumn` may only ever be that column ({@see self::ordered()}). That column
     * IS the registry's manager field, so this much is true of whoever it names — including a
     * value no name can be read out of, which is kept exactly as the registry holds it and left
     * for the news layer to report as unresearched. A role the string states outranks it: the
     * column glues the role onto the name in most of the registry's rows ("Vardas Pavardė,
     * direktorius"), so it goes through {@see PersonName::parse()} — the same rule the manager
     * tables go through — rather than being carried whole as a "name" nothing could match.
     */
    private const MANAGER_ROLE = 'Vadovas';

    /**
     * The registry's people for one firm, in research order: the manager column first,
     * then the manager tables, deduplicated on the normalised name.
     *
     * Deduplication is on {@see CompanyNameTokens::normalize()} — the same key
     * {@see \App\Services\CollectorService} merges on — so the list an operator sees on the
     * console is the list that survives into the profile, rather than one that collapses
     * differently a layer later.
     *
     * A duplicate never displaces the earlier entry, but it does fill in a role the earlier
     * entry left null: a source that says nothing about the role is silent, not authoritative,
     * and silence must not outrank a stated value.
     *
     * @param  ?string  $managerColumn  raw `firms.manager` and NOTHING else, role still
     *                                  glued to the name; another source's "manager" field
     *                                  passed here would have {@see self::MANAGER_ROLE}
     *                                  invented for it and put at the head of the queue
     * @param  array<int, mixed>  $tablePeople  {@see CompanyRegistryRepository::peopleForCode()} output
     * @return array<int, array{name: string, role: ?string}>
     */
    public static function ordered(?string $managerColumn, array $tablePeople): array
    {
        $people = [];

        $manager = PersonName::collapse((string) ($managerColumn ?? ''));
        if ($manager !== '') {
            $parsed = PersonName::parse($manager);
            self::add($people, $parsed['name'] ?? $manager, $parsed['role'] ?? self::MANAGER_ROLE);
        }

        foreach ($tablePeople as $person) {
            if (! is_array($person)) {
                continue;
            }

            $role = trim((string) ($person['role'] ?? ''));
            self::add($people, trim((string) ($person['name'] ?? '')), $role === '' ? null : $role);
        }

        return array_values($people);
    }

    /**
     * @param  array<string, array{name: string, role: ?string}>  $people
     */
    private static function add(array &$people, string $name, ?string $role): void
    {
        $name = PersonName::collapse($name);
        $key = CompanyNameTokens::normalize($name);

        if ($name === '' || $key === '') {
            return;
        }

        if (! isset($people[$key])) {
            $people[$key] = ['name' => $name, 'role' => $role];

            return;
        }

        $people[$key]['role'] ??= $role;
    }
}
