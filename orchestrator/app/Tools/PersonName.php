<?php

namespace App\Tools;

use Illuminate\Support\Str;

/**
 * Tells a person's NAME apart from the role glued onto it in the registry's manager
 * columns, and says when it cannot.
 *
 * A registry typically stores a manager as one free-text string and no rule governs it.
 * In a real registry dataset most values carry a comma, and the role can sit on either
 * side of it, in any case, and can itself contain commas:
 *
 *   Indrė Šilkūnienė, direktorė        Ramūnas Čiurlauskas, Direktorius
 *   direktorė, Asta Norvilavičienė       Daiva Girkūnienė, L.e.p.
 *   Administracijos direktorius Andrius Berčiūnas
 *   Lina Ąžuolienė, Gyventojų aptarnavimo skyriaus vedėja, laikinai vykdanti direktoriaus funkcijas
 *
 * So position and letter case are both unusable as the rule. What IS stable is the
 * vocabulary: the role is drawn from a small closed set of occupational words,
 * while names are not. This class
 * therefore finds the NAME — a run of 2..5 adjacent word tokens that carry no occupational
 * word — and treats everything else in the string as the role.
 *
 * Two rules break the remaining ties, both derived from the data rather than assumed:
 *
 * 1. A run inside a segment that holds no occupational word outranks one inside a segment
 *    that does. That is what keeps "Lina Ąžuolienė" above the "Gyventojų aptarnavimo"
 *    pair sitting in the role segment of the example above.
 * 2. More than one run of the SAME rank means the string names several people
 *    ("Direktoriai: Donatas Tarvydūnas ir Monika Šermukšnienė") or is not a name at all, so
 *    NO name is returned. A wrong name is worse than none: the news dimension searches per
 *    person, and a phrase no article contains is a query that cannot match while still
 *    reporting the person as researched.
 *
 * Rule 2 is bounded by rule 1, and deliberately: when a field names two people and only one of
 * them shares a segment with a role word, the clean one wins outright rather than the pair
 * being refused — "Julija Gintvičienė, Dovydas Gintvičius - įkūrėjai" yields Julija.
 * That is the right trade (the alternative loses correct names wholesale, since a role phrase
 * routinely produces a tier-2 run of its own), but it means the co-founder is neither
 * researched nor reported as dropped. The raw text of the other person survives in `role`, so
 * nothing about them is lost from the record — only from the research plan.
 *
 * A field naming a company rather than a person yields no name either
 * ({@see self::LEGAL_FORMS}).
 *
 * Checked against a real registry dataset, a name is extracted from nearly every value,
 * and where the same person is also stored as a clean comma-free row — ground truth this
 * class did not produce — the extracted name matches it in all but a fraction of a percent
 * of cases.
 *
 * @see \Tests\Unit\PersonNameTest for the observed strings this behaviour is pinned to.
 */
final class PersonName
{
    /**
     * Longest run of tokens still read as one person's name. Lithuanian names are 2-3 words;
     * 5 covers the foreign multi-given-name entries the registry also holds. Beyond that a
     * run is far more likely to be a phrase than a name, and 55 rows in the whole registry
     * are longer.
     */
    private const MAX_NAME_WORDS = 5;

    /**
     * Occupational stems, prefix-matched against an ASCII-folded token, so one entry covers
     * every declension and gender ("direktorius", "direktorė", "direktoriaus", "Direktorius").
     *
     * Every entry was observed in role position in the live registry. Entries whose removal
     * was measured to change nothing but which shared a prefix with a real surname were
     * dropped — a stem is only worth keeping when it earns its collision risk.
     *
     * @var list<string>
     */
    private const ROLE_STEMS = [
        'direktor', 'derektor', 'dirketor', 'vadov', 'prezident', 'viceprezident',
        'pirminink', 'savinink', 'valdytoj', 'likvidator', 'administrator',
        'administracij', 'administravim', 'vedej', 'patarej', 'virsinink', 'virsait',
        'steigej', 'ikurej', 'gydytoj', 'odontolog', 'implantolog', 'kontrolier',
        'rektor', 'ministr', 'sekretor',
        'prokuror', 'prokurist', 'inspektor', 'redaktor', 'klebon', 'kapelion',
        'evangelist', 'superintendent', 'komandor', 'partner', 'advokat', 'notar', 'teisinink',
        'buhalter', 'ukinink', 'akcin', 'atstov', 'igaliot', 'atsaking', 'funkcij',
        'laikin', 'einant', 'vykdant', 'vykdom', 'vykdytoj', 'atliekant', 'pavaduotoj',
        'generalin', 'komercij', 'gamyb', 'marketing', 'transport', 'medicin',
        'technologij', 'projekt', 'padalin', 'filial', 'parapij', 'asociacij',
        'bendruomen', 'bendrij', 'bendrov', 'uzdaroj', 'mokykl', 'studij', 'klinik',
        'savivaldyb', 'susirinkim', 'departament', 'specialist', 'ekspert',
        'vadybinink', 'inzinier', 'programuot', 'dizainer', 'dirigent', 'auklet',
        'seimyn', 'vertej', 'draugij', 'apskait', 'tvarkant', 'bankrot', 'palikim',
    ];

    /**
     * Role words matched whole, not by prefix: either too short for a prefix to be safe, or
     * sharing a stem with a real Lithuanian surname the registry actually holds. Matching
     * "pastorius" exactly keeps the director surnamed Girdaitis; the stem would not.
     *
     * Legal forms are here too, so they can never be part of a name — but keeping them out
     * of the name is not enough on its own, which is what {@see self::LEGAL_FORMS} is for.
     *
     * @var list<string>
     */
    private const ROLE_WORDS = [
        'vadas', 'narys', 'nare', 'nariai', 'nariu', 'asmuo', 'taryb', 'tarybos',
        'valdyb', 'valdybos', 'klub', 'klubo', 'imone', 'imones', 'ured', 'uredas',
        'krivis', 'krive', 'abatas', 'kunigas', 'kunige', 'kun', 'br', 'pastorius',
        'pastore', 'vyskupas', 'seniunas', 'seniune', 'seniunu', 'pareigas',
        'pareigos', 'pareigunas', 'vyriausias', 'vyriausiasis', 'vyriausioji', 'vyr',
        'mazosios', 'kapitonas', 'prasau', 'neskelbiama', 'ir', 'bei', 'arba', 'dr', 'gyd',
        'hab', 'hp', 'prof', 'dir', 'ceo', 'cfo', 'coo', 'cto', 'director',
        'manager', 'managing', 'general', 'founder', 'executive', 'company', 'owner',
        'president', 'chairman', 'chief', 'uab', 'mb', 'ab', 'ii', 'vsi', 'tub', 'mab',
    ];

    /**
     * Legal-form tokens: the manager column sometimes holds a COMPANY rather than a person,
     * and a field naming a company must yield no name at all.
     *
     * A whole-entry test, not a per-segment one, and that is the point of it being separate
     * from {@see self::ROLE_WORDS}: as a mere role word a legal form only demotes its own
     * segment, so "UAB Blizgesio Meistrai" still produced the "person" Blizgesio Meistrai through
     * the tier-2 fallback, "Alfa Turto Valdymas, UAB" kept a clean first segment
     * altogether, and "Audra (UAB Stangrus) Radviloviene" produced the chimera
     * Stangrus Radviloviene — a name ordinary enough that nothing downstream could tell it was
     * wrong. 22 rows in the live registry, every one of them researched and badged green.
     *
     * Abbreviations only, and only when the token does not introduce a role
     * ({@see self::namesACompany()}): the spelled-out forms are already handled by the
     * occupational stems, and adding them here refused "Andrius Rudzinskas, bendrovės vadovas"
     * — where the word names the person's employer inside a job title, not the field's subject.
     *
     * `ii` is deliberately NOT here although it is the folded form of the legal form IĮ: it is
     * also the generational suffix in "Arthur Dunmore Hale II", where the row does name a
     * person. As a role word it stays out of the name; as a legal form it would cost that
     * person their research.
     *
     * @var list<string>
     */
    private const LEGAL_FORMS = ['uab', 'mb', 'ab', 'vsi', 'tub', 'mab'];

    /**
     * Split one registry manager string into the person's name and their role.
     *
     * `name` is null when no single name can be identified — several equally-ranked
     * candidates (usually several people in one field), a bare role, a phone number, a
     * company. The caller keeps the raw string in that case (it is what the registry holds)
     * but must not research it; {@see self::isResearchable()} is the gate for that.
     *
     * @return array{name: ?string, role: ?string}
     */
    public static function parse(string $entry): array
    {
        $entry = self::decodeEntities(self::collapse($entry));

        if ($entry === '') {
            return ['name' => null, 'role' => null];
        }

        $segments = self::segments($entry);

        if (self::namesACompany($segments)) {
            return ['name' => null, 'role' => null];
        }

        [$tier1, $tier2] = self::candidates($segments);
        $candidates = $tier1 !== [] ? $tier1 : $tier2;

        if (count($candidates) !== 1) {
            return ['name' => null, 'role' => null];
        }

        [$segmentIndex, $start, $length] = $candidates[0];
        $words = [];
        for ($i = $start; $i < $start + $length; $i++) {
            $words[] = $segments[$segmentIndex]['tokens'][$i]['bare'];
        }

        return [
            'name' => implode(' ', $words),
            'role' => self::role($segments, $segmentIndex, $start, $length),
        ];
    }

    /**
     * Whether this string may be used as a person-search subject as it stands.
     *
     * True only for a string that is a name and nothing else, so a value that still carries
     * a role ("Indrė Šilkūnienė, direktorė"), or that names nobody, is refused. The news
     * dimension asks this before planning a person's queries: issuing a query for a phrase
     * no article can contain, and then reporting that person as researched, is the false
     * claim the person report exists to prevent.
     *
     * Deliberately not "extract a name and search for that": the name the run reports as
     * searched has to be the name the profile stored, and quietly searching for something
     * else would put the two out of step where nobody can see it.
     */
    public static function isResearchable(string $name): bool
    {
        $parsed = self::parse($name);

        return $parsed['name'] !== null && $parsed['name'] === self::collapse($name);
    }

    /** Whitespace collapsed to single spaces and trimmed — the one spelling of a raw value. */
    public static function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * Undo one round of HTML encoding before anything is tokenised.
     *
     * The registry stores apostrophes encoded, and `&#039;` carries a semicolon — which this
     * class reads as a segment separator, so "Karim Sa&#039;id Hassan Farouk" was cut in
     * half and the residue "id" opened the name. Decoded, the apostrophe is an ordinary part
     * of a name word and the whole name survives.
     *
     * One round only. Doubly-encoded values (`&amp;quot;`) keep an `&` and a `;` after it and
     * so remain outside any name, which is correct: every one of them in the registry is a
     * quoted company name rather than a person.
     */
    private static function decodeEntities(string $value): string
    {
        return self::collapse(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Whether any token is a legal form standing for the field's subject, i.e. this field
     * names a company.
     *
     * A legal form directly followed by an occupational word is excepted, because there it
     * names the person's employer inside their job title — "įmonės Arvydonas, UAB vadovas" is a
     * manager, not a company. Everywhere else a legal form is a company designation: leading
     * one ("UAB Blizgesio Meistrai"), trailing one ("Pentra Electronics UAB"), or alone in its own
     * segment ("Alfa Turto Valdymas, UAB").
     *
     * @param list<array{tokens: list<array{raw: string, bare: string, name: bool, legal: bool}>, roleful: bool}> $segments
     */
    private static function namesACompany(array $segments): bool
    {
        foreach ($segments as $segment) {
            foreach ($segment['tokens'] as $i => $token) {
                if (! $token['legal']) {
                    continue;
                }

                $next = $segment['tokens'][$i + 1] ?? null;
                if ($next === null || $next['name'] || $next['bare'] === '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tokenise into comma/semicolon segments, classifying each word as name-shaped,
     * occupational or a legal form, and recording whether the segment holds any occupational
     * word at all.
     *
     * @return list<array{tokens: list<array{raw: string, bare: string, name: bool, legal: bool}>, roleful: bool}>
     */
    private static function segments(string $entry): array
    {
        $segments = [];

        foreach (preg_split('/[,;]/u', $entry) ?: [] as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            $tokens = [];
            $roleful = false;
            foreach (preg_split('/\s+/u', $raw) ?: [] as $word) {
                $bare = trim($word, " \t.,;:()[]{}\"'`\\/-–—");
                $folded = Str::ascii(mb_strtolower($bare));
                $isRole = $folded !== '' && self::isRoleWord($folded);
                $roleful = $roleful || $isRole;
                $tokens[] = [
                    'raw' => $word,
                    'bare' => $bare,
                    'name' => ! $isRole && self::isNameWord($bare),
                    'legal' => in_array($folded, self::LEGAL_FORMS, true),
                ];
            }

            $segments[] = ['tokens' => self::joinSpacedSurnames($tokens), 'roleful' => $roleful];
        }

        return $segments;
    }

    /**
     * Join a double surname the registry spaces out — "Milda Žiogutė - Daugžienė" — into the
     * one token it is.
     *
     * A lone dash trims to the empty string, which is not a name word, so it closed the run:
     * the name lost its second half and that half was reported as the person's ROLE. 75
     * distinct values in the live registry are written this way.
     *
     * Only between two name-shaped words, which is what keeps the dash meaning "and now the
     * role" intact: in "Dieter Brandtner - klebonas" and "Administracijos vadovė - Aistė
     * Vėtraitė" one side is occupational, so nothing is joined.
     *
     * @param list<array{raw: string, bare: string, name: bool, legal: bool}> $tokens
     *
     * @return list<array{raw: string, bare: string, name: bool, legal: bool}>
     */
    private static function joinSpacedSurnames(array $tokens): array
    {
        $out = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $isDash = $tokens[$i]['bare'] === '' && preg_match('/^[-–—]+$/u', $tokens[$i]['raw']) === 1;
            $joinable = $isDash
                && $out !== []
                && ($out[count($out) - 1]['name'] ?? false)
                && ($tokens[$i + 1]['name'] ?? false);

            if (! $joinable) {
                $out[] = $tokens[$i];

                continue;
            }

            $previous = array_pop($out);
            $next = $tokens[++$i];
            $out[] = [
                'raw' => $previous['raw'] . '-' . $next['raw'],
                'bare' => $previous['bare'] . '-' . $next['bare'],
                'name' => true,
                'legal' => false,
            ];
        }

        return $out;
    }

    /**
     * Every run of adjacent name-shaped words, split into two tiers: runs from segments with
     * no occupational word (tier 1) and runs from segments that carry one (tier 2).
     *
     * The tiers are what let a role phrase share the string with a name without stealing it:
     * a role segment can still produce a run when two of its words are outside the
     * occupational vocabulary ("Gyventojų aptarnavimo"), and such a run must never outrank
     * the clean segment that holds the actual person.
     *
     * @param list<array{tokens: list<array{raw: string, bare: string, name: bool, legal: bool}>, roleful: bool}> $segments
     *
     * @return array{0: list<array{0: int, 1: int, 2: int}>, 1: list<array{0: int, 1: int, 2: int}>}
     */
    private static function candidates(array $segments): array
    {
        $tier1 = [];
        $tier2 = [];

        foreach ($segments as $index => $segment) {
            $start = null;
            $count = count($segment['tokens']);

            // One past the last token, so a run reaching the end of the segment is closed.
            for ($i = 0; $i <= $count; $i++) {
                if ($i < $count && $segment['tokens'][$i]['name']) {
                    $start ??= $i;

                    continue;
                }

                if ($start === null) {
                    continue;
                }

                $length = $i - $start;
                if ($length >= 2 && $length <= self::MAX_NAME_WORDS) {
                    if ($segment['roleful']) {
                        $tier2[] = [$index, $start, $length];
                    } else {
                        $tier1[] = [$index, $start, $length];
                    }
                }

                $start = null;
            }
        }

        return [$tier1, $tier2];
    }

    /**
     * Everything the name did not claim, in the registry's own words and original order.
     *
     * Kept as raw text rather than rebuilt from the recognised role words: the registry
     * writes roles we have no vocabulary for ("L.e.p.", "IV vykdytojas"), and dropping what
     * we do not recognise would lose the only statement of the person's position.
     *
     * @param list<array{tokens: list<array{raw: string, bare: string, name: bool, legal: bool}>, roleful: bool}> $segments
     */
    private static function role(array $segments, int $nameSegment, int $start, int $length): ?string
    {
        $parts = [];

        foreach ($segments as $index => $segment) {
            $words = [];
            foreach ($segment['tokens'] as $i => $token) {
                if ($index === $nameSegment && $i >= $start && $i < $start + $length) {
                    continue;
                }
                $words[] = $token['raw'];
            }

            $text = trim(implode(' ', $words));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        // A trailing full stop is NOT trimmed: the registry abbreviates roles down to
        // "L.e.p." and cutting the last dot leaves a word that is not what it holds.
        $role = trim(implode(', ', $parts), " \t,;:-–—");

        return $role === '' ? null : $role;
    }

    private static function isRoleWord(string $folded): bool
    {
        if (in_array($folded, self::ROLE_WORDS, true)) {
            return true;
        }

        foreach (self::ROLE_STEMS as $stem) {
            if (str_starts_with($folded, $stem)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A word that can be part of a name: letters only, two or more of them, with internal
     * hyphens and apostrophes allowed for double surnames. That excludes a digit anywhere in
     * the token, a single-letter initial ("A."), and any leftover markup — which is what keeps
     * a phone number from producing a "person".
     *
     * "Excludes markup" is a statement about whole tokens, not about the string: `&#039;`
     * carries a semicolon, this class splits segments on semicolons, and the tail of a split
     * entity is letters. Entities are therefore decoded before tokenising
     * ({@see self::decodeEntities()}) rather than being relied on to fail this test.
     */
    private static function isNameWord(string $bare): bool
    {
        return mb_strlen($bare) >= 2
            && preg_match('/^\p{L}[\p{L}\p{M}]*(?:[\'’\-][\p{L}\p{M}]+)*$/u', $bare) === 1;
    }
}
