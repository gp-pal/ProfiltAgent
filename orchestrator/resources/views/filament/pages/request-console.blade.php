<x-filament-panels::page>
    @php
        // Filament ships its stock stylesheet only, so Tailwind utilities written in this
        // view do not exist at runtime. Everything this page lays out itself is styled inline.
        $muted = 'color:#a1a1aa';
        $thStyle = 'text-align:left;padding:0 1.25rem 0.5rem 0;font-size:0.75rem;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;white-space:nowrap;border-bottom:1px solid rgba(161,161,170,0.3);' . $muted;
        $tdStyle = 'padding:0.625rem 1.25rem 0.625rem 0;vertical-align:top;font-size:0.875rem;line-height:1.5;border-bottom:1px solid rgba(161,161,170,0.15)';
        $tdTight = $tdStyle . ';white-space:nowrap';
        $noteStyle = 'font-size:0.875rem;' . $muted;
    @endphp

    {{-- Read via $this->, not a bare $registryUnreachable: Livewire extracts public
         properties into the view as a snapshot taken before rendering starts, so a
         bare variable here would never see the value loadRecords() sets while the
         table below is rendering. --}}
    @if (! $this->registryConfigured)
        {{-- No registry, no search: an empty filter row and an empty table would only
             push the form the operator actually needs below the fold. --}}
        <p style="{{ $noteStyle }}">
            No registry database is configured &mdash; enter the company's details by hand below.
        </p>
    @else
        <x-filament::section>
            <x-slot name="heading">Registry search</x-slot>
            <x-slot name="description">
                Find the company in the registry and press “Fill” — the parameters are filled from the registry.
            </x-slot>

            {{ $this->filterForm }}

            <div style="margin-top:1rem">
                <x-filament::button wire:click="search" icon="heroicon-o-magnifying-glass">
                    Search
                </x-filament::button>
            </div>

            <div style="margin-top:1rem">
                {{ $this->table }}
            </div>

            @if ($this->registryUnreachable)
                <p style="margin-top:0.75rem;font-size:0.875rem;color:#f59e0b">
                    The registry database is unreachable &mdash; check the connection and try again.
                </p>
            @endif
        </x-filament::section>
    @endif

    {{ $this->form }}

    @if ($error)
        <x-filament::section>
            <x-slot name="heading">Request failed</x-slot>
            <pre style="font-size:0.875rem;color:#fb7185;white-space:pre-wrap">{{ $error }}</pre>
        </x-filament::section>
    @endif

    @if ($result)
        @php
            $coverage = $result['coverage'] ?? [];
            $scores = $coverage['fields'] ?? [];
            $status = $result['field_status'] ?? [];
            $sources = $result['field_sources'] ?? [];
            $profile = $result['profile'] ?? [];
            $people = $profile['people'] ?? [];
            // Data is worth exactly as much as the match verdict behind it. Two scopes, two
            // questions, deliberately not interchangeable:
            //
            //  - $unverifiedSources — what THIS run's sources rested on, for the run badges and
            //    the people list, which are attributed to a source rather than to a field.
            //  - $fieldVerification — what is STORED about each field's own value, for the field
            //    table. The table lists the accumulated profile, so a value may predate this run
            //    entirely; keyed by this run's sources it would go unmarked, and a missing badge
            //    reads as verified. 'unverified' means nothing checked that the value belongs to
            //    this company (a judge outage, or no verdict) and it was accepted anyway, by
            //    design; 'unknown' means we hold no record — a value written before verification
            //    was stored — which is a different fact and says so.
            $unverifiedSources = [];
            foreach (($result['match_verdicts'] ?? []) as $sourceName => $match) {
                if (($match['verified'] ?? true) === false) {
                    $unverifiedSources[(string) $sourceName] = (string) ($match['basis'] ?? '');
                }
            }
            $fieldVerification = $result['field_verification'] ?? [];

            $verdictColor = match ($result['verdict'] ?? '') {
                'sufficient' => 'success',
                'partial' => 'warning',
                'data_limited' => 'danger',
                default => 'gray',
            };

            // THIS run's news record, keyed to its hash, or nothing at all. The news action's
            // detail is written unconditionally on every NewsSource path (ok, empty, error
            // alike), so it is the one place a run's own report always lands.
            //
            // There is deliberately NO fallback to $profile['news']. That blob is the
            // accumulated per-company one, written only by CollectorService::mergeNews() and
            // gated on at least one surviving mention, so on any run without mentions it
            // holds an EARLIER run's report — and reading it here rendered that older run's
            // mentions, and its "news searched" badges, as this run's. Three doors reach
            // that state, all with a previous mentions-producing run behind them, which is
            // ordinary for a re-run:
            //   (a) react.news.enabled=false — CollectorService records no news action at all;
            //   (b) a `skipped` result — every portal disabled at /admin/news-portals, or a
            //       name with no searchable token — whose meta carries no 'news' key;
            //   (c) json_encode() returning false on invalid UTF-8 in a scraped article
            //       title, leaving detail null. That one is driven by third-party text.
            // A run we hold no news record for therefore gets no Naujienos section and no
            // badges beside anyone: we know nothing about it, so the page says nothing. A
            // heading reading "News (0)" would itself be the claim we cannot support.
            //
            // Both sections below — Asmenys and Naujienos — read this ONE resolved report,
            // so neither can drift into a claim the other contradicts: showing "paieška
            // liko be atsakymo" next to a person while a few headings down the page still
            // insisted "No mentions found" was worse than the original defect, because an
            // operator seeing both statements on one screen has no way to tell which to
            // trust.
            $newsAction = filled($result['run_hash'] ?? null)
                ? \App\Models\CollectionAction::query()
                    ->where('run_hash', $result['run_hash'])
                    ->where('source', 'news')
                    ->latest('id')
                    ->first()
                : null;
            $newsReport = null;
            if ($newsAction !== null && $newsAction->detail !== null) {
                $decodedNewsDetail = json_decode((string) $newsAction->detail, true);
                $newsReport = is_array($decodedNewsDetail) && is_array($decodedNewsDetail['news'] ?? null)
                    ? $decodedNewsDetail['news']
                    : null;
            }
            $news = is_array($newsReport['mentions'] ?? null) ? $newsReport['mentions'] : [];
            $searchedPeople = is_array($newsReport['people_searched'] ?? null) ? $newsReport['people_searched'] : [];
            $droppedPeopleReasons = is_array($newsReport['people_dropped_reasons'] ?? null) ? $newsReport['people_dropped_reasons'] : [];

            // Why the state and not the action's status: 'error' means BOTH "no query was
            // answered" and "every query was answered and returned an empty list", and the
            // two need opposite sentences. The second one credits its people in
            // people_searched, so keying on the status alone printed "The search was not
            // answered" on the same screen as a green "news searched" next to the very
            // person that search had asked about. NewsSource::searchState() decides which of
            // the three happened; this page only words it. 'empty' and 'ok' both mean the
            // search answered and filtered down to what is in the table below, which is what
            // "No mentions found" honestly describes.
            //
            // No state on a report we DO hold — a blob written before this key existed — is
            // not evidence of a blind run, so it keeps the plain "no mentions" reading it
            // always had. Holding no report at all is handled above, by rendering nothing.
            $newsUncertainty = match ($newsReport['search_state'] ?? null) {
                \App\Services\NewsSource::SEARCH_UNANSWERED => 'The search was not answered — unknown whether mentions exist.',
                \App\Services\NewsSource::SEARCH_RETURNED_NOTHING => 'The search returned nothing — unknown whether mentions exist.',
                \App\Services\NewsSource::SEARCH_OFF_PORTAL => 'The search did not reach the news portals — unknown whether mentions exist.',
                default => null,
            };
        @endphp

        <x-filament::section>
            <x-slot name="heading">Result</x-slot>

            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem">
                <x-filament::badge :color="$verdictColor" size="lg">
                    {{ $result['verdict'] ?? '?' }}
                </x-filament::badge>
                <x-filament::badge color="gray" size="lg">
                    coverage {{ number_format((float) ($coverage['overall'] ?? 0), 3) }}
                </x-filament::badge>
                <x-filament::badge color="gray" size="lg">{{ $elapsed }}s</x-filament::badge>
                @foreach (($result['sources_used'] ?? []) as $s)
                    <x-filament::badge :color="isset($unverifiedSources[$s]) ? 'warning' : 'info'">
                        {{ $s }}@if (isset($unverifiedSources[$s])) · match not verified @endif
                    </x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Fields</x-slot>
            <x-slot name="description">
                Every declared field: where it came from, how it scored and what it holds.
            </x-slot>

            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr>
                            <th style="{{ $thStyle }}">Field</th>
                            <th style="{{ $thStyle }}">Status</th>
                            <th style="{{ $thStyle }}">Source</th>
                            <th style="{{ $thStyle }}">Score</th>
                            <th style="{{ $thStyle }};width:60%">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($scores as $field => $score)
                            @php
                                $st = $status[$field] ?? null;
                                $stColor = match ($st) {
                                    'supplied' => 'info',
                                    'found' => 'success',
                                    'not_found' => 'danger',
                                    default => 'gray',
                                };
                                $value = $profile[$field] ?? null;
                                $rendered = is_array($value) ? implode(' · ', array_map('strval', $value)) : (string) ($value ?? '');
                            @endphp
                            <tr>
                                <td style="{{ $tdTight }};font-weight:500">{{ $field }}</td>
                                <td style="{{ $tdTight }}">
                                    <x-filament::badge :color="$stColor" style="display:inline-flex">
                                        {{ $st ?? 'budget spent' }}
                                    </x-filament::badge>
                                </td>
                                <td style="{{ $tdTight }};{{ $muted }}">
                                    {{ $sources[$field] ?? '—' }}
                                    @if (($fieldVerification[$field]['status'] ?? null) === \App\Models\CompanyProfile::VERIFICATION_UNVERIFIED)
                                        <span title="Source accepted without a match check: {{ $fieldVerification[$field]['basis'] ?? '—' }}">
                                            <x-filament::badge color="warning">match not verified</x-filament::badge>
                                        </span>
                                    @elseif (($fieldVerification[$field]['status'] ?? null) === \App\Models\CompanyProfile::VERIFICATION_UNKNOWN)
                                        <span title="Value stored before match verification was recorded">
                                            <x-filament::badge color="gray">no verification record</x-filament::badge>
                                        </span>
                                    @endif
                                </td>
                                <td style="{{ $tdTight }};font-variant-numeric:tabular-nums">{{ number_format((float) $score, 2) }}</td>
                                <td style="{{ $tdStyle }}">
                                    <div style="max-height:6rem;overflow:auto;overflow-wrap:anywhere">{{ $rendered !== '' ? $rendered : '—' }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">People ({{ count($people) }})</x-slot>
            @if ($people === [])
                <p style="{{ $noteStyle }}">No people.</p>
            @else
                <ul style="list-style:none;margin:0;padding:0;display:grid;gap:0.5rem;font-size:0.875rem">
                    @foreach ($people as $p)
                        @php
                            // ->first() with a callback, not ->firstWhere('name', ...): firstWhere()
                            // compares loosely (==), while the searched-badge check above it is
                            // strict (in_array(..., true)) — an inconsistency the reviewer flagged.
                            $personName = $p['name'] ?? null;
                            $droppedReason = collect($droppedPeopleReasons)
                                ->first(static fn (array $reason): bool => ($reason['name'] ?? null) === $personName);
                        @endphp
                        <li style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem">
                            <span style="font-weight:500">{{ $p['name'] ?? '?' }}</span>
                            @if (($p['role'] ?? null) !== null && $p['role'] !== '')
                                <span style="{{ $muted }}">{{ $p['role'] }}</span>
                            @endif
                            <x-filament::badge :color="($p['source'] ?? '') === 'caller' ? 'info' : 'gray'">
                                {{ $p['source'] ?? '?' }}
                            </x-filament::badge>
                            {{-- People are gated on the same match verdict as fields, so the
                                 same marker belongs here. Marking the field table alone was
                                 worse than marking nothing: on one screen, a missing badge
                                 beside a name reads as "this person was verified". --}}
                            @if (isset($unverifiedSources[$p['source'] ?? '']))
                                <span title="Source accepted without a match check: {{ $unverifiedSources[$p['source']] }}">
                                    <x-filament::badge color="warning">match not verified</x-filament::badge>
                                </span>
                            @endif
                            @if (in_array($p['name'] ?? '', $searchedPeople, true))
                                <x-filament::badge color="success">news searched</x-filament::badge>
                            @elseif ($droppedReason !== null)
                                {{-- 'unusable_name' is neither a failure of the search nor a
                                     budget we chose: the stored value is not a name we could
                                     search for, so it is the DATA that needs fixing. Warning,
                                     not gray, because a gray badge reads as "as configured". --}}
                                <x-filament::badge :color="match ($droppedReason['reason']) {
                                    'search_unanswered' => 'danger',
                                    'unusable_name' => 'warning',
                                    default => 'gray',
                                }">
                                    {{ match ($droppedReason['reason']) {
                                        'search_unanswered' => 'search not answered',
                                        'time_budget' => 'time budget spent',
                                        'query_budget' => 'query budget spent',
                                        'unusable_name' => 'name not recognised — not searched',
                                        default => $droppedReason['reason'],
                                    } }}
                                </x-filament::badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        {{-- Only when this run has a news record of its own. No record is not "no mentions":
             the section, its count and its badges would all be describing a different run. --}}
        @if ($newsReport !== null)
        <x-filament::section>
            {{-- The count is a claim as much as the sentence under it. On both uncertainty
                 paths below, the body says the number of mentions is unknown, so a heading
                 reading "News (0)" would contradict it on one screen — the same reason
                 the whole section is dropped when this run has no record at all. '?' is this
                 page's own idiom for a value it does not have (see the verdict, portal and
                 name fallbacks). An answered run keeps its real count, zero included: that
                 one IS a claim this run can support. --}}
            <x-slot name="heading">News ({{ $newsUncertainty === null ? count($news) : '?' }})</x-slot>
            <x-slot name="description">
                The “about” column shows whether a mention is attributed to the company or to a specific person.
            </x-slot>

            @if ($newsUncertainty !== null)
                <p style="font-size:0.875rem;color:#f59e0b">{{ $newsUncertainty }}</p>
            @elseif ($news === [])
                <p style="{{ $noteStyle }}">No mentions found.</p>
            @else
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse">
                        <thead>
                            <tr>
                                <th style="{{ $thStyle }}">Portal</th>
                                <th style="{{ $thStyle }}">Date</th>
                                <th style="{{ $thStyle }}">About</th>
                                <th style="{{ $thStyle }};width:70%">Headline</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($news as $m)
                                <tr>
                                    <td style="{{ $tdTight }};{{ $muted }}">{{ $m['portal'] ?? '?' }}</td>
                                    <td style="{{ $tdTight }};font-variant-numeric:tabular-nums;{{ $muted }}">{{ $m['date'] ?? '—' }}</td>
                                    <td style="{{ $tdTight }}">
                                        <x-filament::badge :color="($m['person'] ?? null) ? 'info' : 'gray'" style="display:inline-flex">
                                            {{ $m['person'] ?? 'company' }}
                                        </x-filament::badge>
                                    </td>
                                    <td style="{{ $tdStyle }}">
                                        <a href="{{ $m['url'] ?? '#' }}" target="_blank" rel="noopener noreferrer"
                                           style="color:#818cf8;font-weight:500">
                                            {{ \Illuminate\Support\Str::limit((string) ($m['title'] ?? '?'), 110) }}
                                        </a>
                                        @if (!empty($m['excerpt']))
                                            {{-- The article's own words, extracted from the page. Never the raw
                                                 HTML: that is kept as an artifact for a human to open. --}}
                                            <div style="margin-top:0.375rem;max-height:6rem;overflow:auto;font-size:0.8125rem;{{ $muted }}">
                                                {{ $m['excerpt'] }}
                                            </div>
                                        @elseif (($m['excerpt_state'] ?? null) === \App\Services\NewsSource::EXCERPT_NO_BODY)
                                            {{-- Said out loud rather than left blank: the page WAS downloaded and
                                                 converted, and no article text was found in it. Deliberately does not
                                                 name a cause: no_body_found covers a topic page, a paywall stub and a
                                                 JS-only shell alike, and the state cannot tell them apart. The stored
                                                 file below is what settles which one it was. --}}
                                            <div style="margin-top:0.375rem;font-size:0.8125rem;font-style:italic;{{ $muted }}">
                                                No article text was found on this page – the page was downloaded, but
                                                no article body was recognised in it. See the downloaded text in the file.
                                            </div>
                                        @endif
                                        @if (!empty($m['artifacts']['markdown']))
                                            {{-- Outside the excerpt branch on purpose: when the body was not located,
                                                 the stored text is exactly what the operator needs to open. --}}
                                            <div style="margin-top:0.375rem;font-family:ui-monospace,monospace;font-size:0.6875rem;overflow-wrap:anywhere;color:#71717a">
                                                {{ $m['artifacts']['markdown'] }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
        @endif

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Raw response</x-slot>
            <pre style="font-size:0.75rem;white-space:pre-wrap;overflow-wrap:anywhere;max-height:24rem;overflow:auto">{{ json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </x-filament::section>
    @endif
</x-filament-panels::page>
