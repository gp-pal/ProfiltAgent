<?php

namespace App\Filament\Resources\CollectionRuns\Schemas;

use App\Filament\Support\NewsSearchNotice;
use App\Models\CollectionRun;
use App\Services\ProfileCoverageScorer;
use App\Tools\SessionFs;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\HtmlString;
use Throwable;

class CollectionRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Single top-level column: sections stack full-width instead of
            // tiling two-across (a tall Actions section beside a short Run
            // section left the page lopsided and hard to scan).
            ->columns(1)
            ->components([
                // In-page tabs: the view opens on Overview (the at-a-glance
                // assessment); Actions / Artifacts / Collected payload are one click
                // away instead of a long scroll. The active tab persists in the URL.
                Tabs::make()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Overview')
                            ->schema([
                // Summary leads with the "so what" (verdict/score/status/sources);
                // low-value dev metadata (hash/session/timestamps) is demoted into
                // a recessive "Run metadata" group instead of competing for weight.
                Section::make('Run')
                    ->schema([
                        Grid::make(['default' => 2, 'sm' => 4])
                            ->schema([
                                TextEntry::make('verdict')
                                    ->badge()
                                    ->color(fn (?string $state): string => self::verdictColor($state))
                                    ->placeholder('-'),
                                TextEntry::make('overall_score')
                                    ->label('Score')
                                    ->badge()
                                    ->numeric(decimalPlaces: 2)
                                    ->color(fn (?float $state): string => self::scoreColor($state)),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'done' => 'success',
                                        'failed' => 'danger',
                                        'running' => 'warning',
                                        default => 'gray',
                                    }),
                                TextEntry::make('sources_used')
                                    ->label('Sources')
                                    ->badge()
                                    ->placeholder('-'),
                            ]),
                        Fieldset::make('Run metadata')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('name'),
                                TextEntry::make('company_code')
                                    ->placeholder('-'),
                                TextEntry::make('run_hash')
                                    ->fontFamily(FontFamily::Mono)
                                    ->copyable(),
                                TextEntry::make('company_key')
                                    ->fontFamily(FontFamily::Mono),
                                TextEntry::make('session')
                                    ->fontFamily(FontFamily::Mono)
                                    ->copyable()
                                    ->placeholder('-'),
                                TextEntry::make('langfuse')
                                    ->label('Langfuse')
                                    ->placeholder('-')
                                    ->state(fn (CollectionRun $record): ?string => filled(self::langfuseSessionUrl($record->session))
                                        ? $record->session
                                        : null)
                                    ->url(fn (?string $state): ?string => self::langfuseSessionUrl($state))
                                    ->openUrlInNewTab(),
                                TextEntry::make('started_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('finished_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('created_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('updated_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                            ]),
                    ]),
                Section::make('Field diagnosis')
                    ->description('Per-field coverage — whether each target field was collected and by which source.')
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('field_diagnosis_summary')
                            ->hiddenLabel()
                            ->state(fn (CollectionRun $record): string => self::fieldDiagnosisSummary($record))
                            ->badge()
                            ->color(fn (CollectionRun $record): string => self::missingFields($record) === [] ? 'success' : 'warning')
                            ->columnSpanFull(),
                        KeyValueEntry::make('field_diagnosis')
                            ->hiddenLabel()
                            ->keyLabel('Field')
                            ->valueLabel('Source(s)')
                            ->state(fn (CollectionRun $record): array => self::fieldDiagnosisDisplay($record))
                            ->columnSpanFull(),
                    ]),
                // NOT collapsible + bounded self-scrolling: a ->state() HtmlString in
                // an Alpine x-collapse body can clip to ~0; the box also keeps many
                // mentions fitting the page. Mirrors the CompanyProfile section.
                Section::make('News mentions')
                    ->description('Third-party LT news-portal mentions for this run (separate from the coverage score).')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('news_block')
                            ->hiddenLabel()
                            ->state(fn (CollectionRun $record): HtmlString => self::newsBlock(self::newsFromRun($record), self::newsSearchFailure($record)))
                            ->columnSpanFull()
                            ->extraAttributes(['style' => 'display:block;max-height:24rem;overflow-y:auto'])
                            ->html(),
                    ]),
                            ]),
                        Tab::make('Actions')
                            ->schema([
                // One compact table (Source · Status · Fields filled · Detail)
                // instead of a tall card per source. Skipped sources collapse to a
                // single line; the JSON detail/yielded sit behind a native
                // <details> disclosure (escaped) rather than always-open blocks.
                Section::make('Actions')
                    ->description('Per-source outcomes for this run.')
                    ->collapsible()
                    ->schema([
                        TextEntry::make('actions_table')
                            ->hiddenLabel()
                            ->state(fn (CollectionRun $record): HtmlString => self::actionsTable($record))
                            ->columnSpanFull()
                            ->html(),
                    ]),
                            ]),
                        Tab::make('Artifacts')
                            ->schema([
                Section::make('Artifacts')
                    ->description('Captured Step-1 run artifacts (summary, profile, screenshots).')
                    ->schema([
                        TextEntry::make('artifact_summary')
                            ->label('Summary')
                            ->state(fn (CollectionRun $record): ?string => self::readSummary($record))
                            ->markdown()
                            ->placeholder('No summary captured.')
                            ->columnSpanFull()
                            ->visible(fn (CollectionRun $record): bool => self::hasArtifacts($record)),
                        TextEntry::make('artifact_profile')
                            ->label('Profile')
                            ->state(fn (CollectionRun $record): ?string => self::readProfileJson($record))
                            ->placeholder('No profile captured.')
                            ->columnSpanFull()
                            ->html()
                            ->formatStateUsing(fn (?string $state): HtmlString => self::preBlock((string) $state))
                            ->visible(fn (CollectionRun $record): bool => self::hasArtifacts($record)),
                        ImageEntry::make('artifact_screenshots')
                            ->label('Screenshots')
                            ->state(fn (CollectionRun $record): array => self::screenshotUrls($record))
                            ->checkFileExistence(false)
                            ->placeholder('No screenshots captured.')
                            ->columnSpanFull()
                            ->visible(fn (CollectionRun $record): bool => self::hasArtifacts($record)),
                        TextEntry::make('artifact_none')
                            ->hiddenLabel()
                            ->state('No artifacts captured for this run.')
                            ->columnSpanFull()
                            ->visible(fn (CollectionRun $record): bool => ! self::hasArtifacts($record)),
                    ]),
                            ]),
                        Tab::make('Collected payload')
                            ->schema([
                Section::make('Collected payload')
                    ->description('The full per-run storage payload — what web search returned, the captured page, extracted structure, and the ReAct domain-discovery trace.')
                    ->schema([
                        RepeatableEntry::make('payload_search_results')
                            ->label('Web search results')
                            ->state(fn (CollectionRun $record): array => self::searchResultsDisplay($record))
                            ->schema([
                                TextEntry::make('query')
                                    ->label('Query')
                                    ->columnSpanFull(),
                                TextEntry::make('urls')
                                    ->label('Returned URLs')
                                    ->columnSpanFull()
                                    ->html()
                                    ->formatStateUsing(fn (?string $state): HtmlString => self::preBlock((string) $state, '12rem')),
                            ])
                            ->extraAttributes(self::boundedListStyle())
                            ->placeholder('No web-search results captured for this run.')
                            ->visible(fn (CollectionRun $record): bool => self::searchResultsDisplay($record) !== []),
                        TextEntry::make('payload_main_markdown')
                            ->label('Captured page (markdown)')
                            ->state(fn (CollectionRun $record): ?string => self::payloadMainMarkdown($record))
                            ->markdown()
                            ->placeholder('No captured page markdown for this run.')
                            ->columnSpanFull()
                            ->extraAttributes(self::boundedMarkdownStyle())
                            ->visible(fn (CollectionRun $record): bool => self::payloadMainMarkdown($record) !== null),
                        KeyValueEntry::make('payload_structure_summary')
                            ->label('Page structure — signals')
                            ->keyLabel('Signal')
                            ->valueLabel('Value')
                            ->state(fn (CollectionRun $record): array => self::structureSummaryDisplay($record))
                            ->columnSpanFull()
                            ->visible(fn (CollectionRun $record): bool => self::structureSummaryDisplay($record) !== []),
                        TextEntry::make('payload_structure_raw')
                            ->label('Page structure — full JSON')
                            ->state(fn (CollectionRun $record): ?string => self::payloadStructureJson($record))
                            ->placeholder('No page structure captured for this run.')
                            ->columnSpanFull()
                            ->html()
                            ->formatStateUsing(fn (?string $state): HtmlString => self::preBlock((string) $state))
                            ->visible(fn (CollectionRun $record): bool => self::payloadStructureJson($record) !== null),
                        RepeatableEntry::make('payload_react_trace')
                            ->label('ReAct trace')
                            ->state(fn (CollectionRun $record): array => self::reactTraceDisplay($record))
                            ->schema([
                                TextEntry::make('step')
                                    ->label('Step'),
                                TextEntry::make('text')
                                    ->label('Reasoning')
                                    ->columnSpanFull(),
                                TextEntry::make('tool_calls')
                                    ->label('Tool calls')
                                    ->columnSpanFull()
                                    ->html()
                                    ->formatStateUsing(fn (?string $state): HtmlString => self::preBlock((string) $state, '10rem')),
                                TextEntry::make('tool_results')
                                    ->label('Tool results')
                                    ->columnSpanFull()
                                    ->html()
                                    ->formatStateUsing(fn (?string $state): HtmlString => self::preBlock((string) $state, '10rem')),
                            ])
                            ->columns(2)
                            ->extraAttributes(self::boundedListStyle())
                            ->placeholder('No ReAct trace captured for this run.')
                            ->visible(fn (CollectionRun $record): bool => self::reactTraceDisplay($record) !== []),
                        TextEntry::make('payload_none')
                            ->hiddenLabel()
                            ->state('No collected payload captured for this run.')
                            ->columnSpanFull()
                            ->visible(fn (CollectionRun $record): bool => ! self::hasAnyPayload($record)),
                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * Pretty-print an action's detail/yielded (a JSON string or array) so it
     * reads as an indented block instead of an escaped one-liner. Oversized
     * blobs (e.g. a full scraped page in a contacts action) are truncated so a
     * single action can't flood the page.
     */
    private static function prettyJson(mixed $state): string
    {
        if ($state === null || $state === '' || $state === []) {
            return '-';
        }

        $decoded = is_array($state) ? $state : json_decode((string) $state, true);
        $text = is_array($decoded)
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) $state;

        return mb_strlen($text) > 800
            ? mb_substr($text, 0, 800) . "\n… (truncated)"
            : $text;
    }

    /**
     * Style template (max-height is filled in per call site) for every
     * bounded mono/JSON block, applied to a self-contained <pre> rather than
     * the entry's outer wrapper. Filament's text-entry blade surrounds the
     * rendered value with its own indentation/newlines; a `white-space:
     * pre-wrap` on the OUTER element preserved that template whitespace too,
     * scattering the block. Scoping the style to an inner <pre> means only
     * the value's own whitespace is preserved.
     */
    private const PRE_STYLE = 'margin:0;max-height:%s;overflow:auto;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:0.8rem;line-height:1.45';

    /**
     * Escape $value and wrap it in a self-contained, bounded, scrollable
     * <pre> block. The value is scraped/derived content (never trusted), so
     * it MUST be escaped before being handed to ->html(), which renders the
     * returned HtmlString verbatim without further sanitization.
     */
    private static function preBlock(string $value, string $maxHeight = '18rem'): HtmlString
    {
        return new HtmlString('<pre style="' . sprintf(self::PRE_STYLE, $maxHeight) . '">' . e($value) . '</pre>');
    }

    /**
     * Per-source actions as one compact table (Source · Status · Fields filled ·
     * Detail). Skipped sources collapse to a single muted line; detail/yielded
     * JSON sits behind a native <details> disclosure via the escaped preBlock, so
     * the section is scannable instead of a tall card per source.
     */
    private static function actionsTable(CollectionRun $record): HtmlString
    {
        $rows = '';
        $skipped = [];

        foreach ($record->actions as $action) {
            $source = (string) ($action->source ?? '');
            $status = (string) ($action->status ?? '');

            if ($status === 'skipped') {
                $skipped[] = e($source);

                continue;
            }

            $scores = is_array($action->field_scores) ? $action->field_scores : [];
            $filled = array_keys(array_filter($scores, static fn ($v): bool => (float) $v > 0));
            $fieldsCell = $filled === [] ? '—' : e(implode(', ', $filled));

            $detailJson = self::prettyJson($action->detail);
            $yieldedJson = self::prettyJson($action->yielded);
            if ($detailJson !== '-' || $yieldedJson !== '-') {
                $inner = $detailJson !== '-' ? (string) self::preBlock($detailJson) : '';
                if ($yieldedJson !== '-') {
                    $inner .= '<div style="opacity:0.55;font-size:0.75rem;margin-top:0.25rem">yielded</div>' . (string) self::preBlock($yieldedJson);
                }
                $detailCell = '<details><summary style="cursor:pointer;opacity:0.8">JSON</summary>' . $inner . '</details>';
            } else {
                $detailCell = '<span style="opacity:0.5">—</span>';
            }

            $rows .= '<tr style="border-top:1px solid rgba(148,163,184,0.15);vertical-align:top">'
                . '<td style="padding:0.4rem 0.6rem"><strong>' . e($source) . '</strong></td>'
                . '<td style="padding:0.4rem 0.6rem">' . self::statusChip($status) . '</td>'
                . '<td style="padding:0.4rem 0.6rem">' . self::matchCell($action->detail) . '</td>'
                . '<td style="padding:0.4rem 0.6rem">' . $fieldsCell . '</td>'
                . '<td style="padding:0.4rem 0.6rem;min-width:14rem">' . $detailCell . '</td>'
                . '</tr>';
        }

        if ($rows === '' && $skipped === []) {
            return new HtmlString('<span style="opacity:0.7">No actions recorded.</span>');
        }

        $table = $rows === '' ? '' : (
            '<table style="width:100%;border-collapse:collapse;font-size:0.85rem">'
            . '<thead><tr style="text-align:left;opacity:0.6">'
            . '<th style="padding:0.4rem 0.6rem">Source</th>'
            . '<th style="padding:0.4rem 0.6rem">Status</th>'
            . '<th style="padding:0.4rem 0.6rem">Match</th>'
            . '<th style="padding:0.4rem 0.6rem">Fields filled</th>'
            . '<th style="padding:0.4rem 0.6rem">Detail</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        );

        $skippedLine = $skipped === [] ? '' : (
            '<div style="margin-top:0.5rem;opacity:0.6;font-size:0.8rem">Skipped: ' . implode(', ', $skipped) . '</div>'
        );

        return new HtmlString($table . $skippedLine);
    }

    /**
     * The match verdict this source's contribution rested on, read from the action's stored
     * `detail.match` block ({@see \App\Services\MatchAssessment}). A source accepted with no
     * working verification reads `unverified` here rather than looking like a judged `warn`,
     * which is the whole reason the block is stored. A dash means no match decision was
     * reached at all — the source yielded nothing, or is not match-gated (news).
     */
    private static function matchCell(?string $detail): string
    {
        $decoded = json_decode((string) $detail, true);
        $match = is_array($decoded) ? ($decoded['match'] ?? null) : null;

        if (!is_array($match) || !is_string($match['verdict'] ?? null)) {
            return '<span style="opacity:0.5">—</span>';
        }

        $verdict = (string) $match['verdict'];
        $color = match ($verdict) {
            'pass' => '#10b981',
            'fail' => '#f43f5e',
            'warn', 'unverified' => '#f59e0b',
            default => '#a1a1aa',
        };

        $chip = '<span style="display:inline-block;padding:0.05rem 0.45rem;border-radius:0.3rem;font-size:0.75rem;'
            . 'background:' . $color . '22;color:' . $color . '">' . e($verdict) . '</span>';

        $basis = is_string($match['basis'] ?? null) ? (string) $match['basis'] : '';
        $confidence = is_numeric($match['match_confidence'] ?? null)
            ? ' · ' . number_format((float) $match['match_confidence'], 2)
            : '';

        return $basis === ''
            ? $chip
            : $chip . '<div style="opacity:0.55;font-size:0.7rem;margin-top:0.15rem">' . e($basis) . $confidence . '</div>';
    }

    private static function statusChip(string $status): string
    {
        $color = match ($status) {
            'ok' => '#10b981',
            'error', 'rejected' => '#f43f5e',
            default => '#a1a1aa',
        };

        return '<span style="display:inline-block;padding:0.05rem 0.45rem;border-radius:0.3rem;font-size:0.75rem;'
            . 'background:' . $color . '22;color:' . $color . '">' . e($status) . '</span>';
    }

    /**
     * The news dimension for this run, decoded from its `news` collection-action
     * detail (`{"news": {...}}`). Null when the run had no news action.
     *
     * @return array{score?: float, fresh?: bool, mentions?: array<int, array<string, mixed>>}|null
     */
    private static function newsFromRun(CollectionRun $record): ?array
    {
        foreach ($record->actions as $action) {
            if ((string) ($action->source ?? '') !== 'news') {
                continue;
            }
            $decoded = json_decode((string) $action->detail, true);
            $news = is_array($decoded) ? ($decoded['news'] ?? null) : null;

            return is_array($news) ? $news : null;
        }

        return null;
    }

    /**
     * Why this run's news search could not see, or null when it could (or never ran).
     */
    private static function newsSearchFailure(CollectionRun $record): ?string
    {
        $action = $record->actions->firstWhere('source', 'news');

        return $action === null ? null : NewsSearchNotice::forAction((string) $action->status, $action->detail);
    }

    /**
     * Render the news dimension (score + freshness + mentions) as a self-contained
     * escaped HTML block with visible backlinks. Scraped titles/urls MUST be
     * escaped (->html() renders the HtmlString verbatim). Placeholder when empty.
     *
     * @param array{score?: float, fresh?: bool, mentions?: array<int, array<string, mixed>>}|null $news
     */
    private static function newsBlock(?array $news, ?string $searchFailure = null): HtmlString
    {
        $notice = NewsSearchNotice::html($searchFailure);
        $mentions = is_array($news['mentions'] ?? null) ? $news['mentions'] : [];
        if ($mentions === []) {
            return new HtmlString($notice !== '' ? $notice : '<span style="opacity:0.7">No third-party news mentions.</span>');
        }

        $score = number_format((float) ($news['score'] ?? 0.0), 2);
        $fresh = ($news['fresh'] ?? false) ? 'recent' : 'older';

        $rows = '';
        foreach ($mentions as $mention) {
            $portal = e((string) ($mention['portal'] ?? ''));
            $title = e((string) ($mention['title'] ?? ''));
            $url = e((string) ($mention['url'] ?? ''));
            $date = e((string) ($mention['date'] ?? ''));
            $rows .= '<li style="margin:0.15rem 0"><strong>' . $portal . '</strong> · '
                . '<a href="' . $url . '" target="_blank" rel="noopener noreferrer"'
                . ' style="color:#818cf8;text-decoration:underline">' . $title . '</a>'
                . ($date !== '' ? ' · <span style="opacity:0.7">' . $date . '</span>' : '')
                . '</li>';
        }

        return new HtmlString(
            $notice . '<div style="font-size:0.85rem"><div style="margin-bottom:0.4rem">news_score: <strong>' . e($score)
            . '</strong> · <em>' . e($fresh) . '</em></div>'
            . '<ul style="margin:0;padding-left:1rem;list-style:disc">' . $rows . '</ul></div>',
        );
    }

    /**
     * Inline style for the bounded ->markdown() entry (`payload_main_markdown`).
     * Markdown state is rendered to real HTML (paragraphs, lists, …), which wraps
     * on its own — so this stays a plain scrollable box WITHOUT white-space:
     * pre-wrap. pre-wrap on this outer container preserved Filament's template
     * indentation around the rendered markdown and scattered the block (the same
     * failure the JSON <pre> blocks had); the JSON blocks fixed it by moving the
     * pre-wrap onto a self-contained inner <pre>, and markdown simply doesn't
     * need it.
     *
     * @return array{style: string}
     */
    private static function boundedMarkdownStyle(): array
    {
        return ['style' => 'display:block;max-height:18rem;overflow:auto'];
    }

    private static function scoreColor(?float $score): string
    {
        return match (true) {
            $score === null => 'gray',
            $score >= 0.7 => 'success',
            $score >= 0.5 => 'warning',
            default => 'danger',
        };
    }

    private static function verdictColor(?string $verdict): string
    {
        return match ($verdict) {
            'accept', 'accepted', 'pass', 'sufficient' => 'success',
            'data_limited' => 'info',
            'partial', 'review', 'uncertain' => 'warning',
            'reject', 'rejected', 'fail' => 'danger',
            default => 'gray',
        };
    }

    /**
     * For each canonical target field (ProfileCoverageScorer::FIELDS), collect
     * the source name of every action whose field_scores[field] > 0 — i.e.
     * which source(s), if any, actually provided that field. Read-only derived
     * view over CollectionAction.field_scores; never touches collection logic.
     * Fail-soft: a run with no actions yields every field mapped to an empty
     * list ("not collected").
     *
     * @return array<string, array<int, string>>
     */
    private static function fieldSources(CollectionRun $record): array
    {
        $sources = array_fill_keys(ProfileCoverageScorer::FIELDS, []);

        foreach ($record->actions as $action) {
            $scores = is_array($action->field_scores) ? $action->field_scores : [];
            $source = trim((string) ($action->source ?? ''));

            if ($source === '') {
                continue;
            }

            foreach (ProfileCoverageScorer::FIELDS as $field) {
                if (($scores[$field] ?? 0) > 0) {
                    $sources[$field][] = $source;
                }
            }
        }

        return $sources;
    }

    /**
     * Target fields no action scored > 0 for — the gaps an operator needs to
     * close, in ProfileCoverageScorer::FIELDS order.
     *
     * @return array<int, string>
     */
    private static function missingFields(CollectionRun $record): array
    {
        return array_keys(array_filter(
            self::fieldSources($record),
            static fn (array $providers): bool => $providers === [],
        ));
    }

    /**
     * Headline summary for the Field diagnosis section: which target fields
     * are missing, or a clean "all collected" message when none are.
     */
    private static function fieldDiagnosisSummary(CollectionRun $record): string
    {
        $missing = self::missingFields($record);

        return $missing === []
            ? 'All target fields collected.'
            : 'Missing: ' . implode(', ', $missing);
    }

    /**
     * Per-field breakdown for the KeyValueEntry: field => comma-joined
     * providing source(s), or a "not collected" placeholder.
     *
     * @return array<string, string>
     */
    private static function fieldDiagnosisDisplay(CollectionRun $record): array
    {
        return array_map(
            static fn (array $providers): string => $providers === [] ? '— not collected' : implode(', ', $providers),
            self::fieldSources($record),
        );
    }

    /**
     * Resolve the disk-relative analyze base for a run, fail-soft. Any storage
     * error yields null so the View page can never 500 on artifact lookup.
     */
    private static function analysisBase(CollectionRun $record): ?string
    {
        try {
            return app(SessionFs::class)->findAnalysisBase((string) $record->session);
        } catch (Throwable) {
            return null;
        }
    }

    private static function hasArtifacts(CollectionRun $record): bool
    {
        return self::analysisBase($record) !== null;
    }

    private static function readSummary(CollectionRun $record): ?string
    {
        $base = self::analysisBase($record);

        if ($base === null) {
            return null;
        }

        try {
            return app(SessionFs::class)->readSummary($base);
        } catch (Throwable) {
            return null;
        }
    }

    private static function readProfileJson(CollectionRun $record): ?string
    {
        $base = self::analysisBase($record);

        if ($base === null) {
            return null;
        }

        try {
            $profile = app(SessionFs::class)->readProfile($base);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($profile) || $profile === []) {
            return null;
        }

        return (string) json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Build the streamed-image URLs for each captured screenshot. Each URL
     * points at the secure route; the basename is server-validated there.
     *
     * @return array<int, string>
     */
    private static function screenshotUrls(CollectionRun $record): array
    {
        $base = self::analysisBase($record);

        if ($base === null) {
            return [];
        }

        try {
            $names = app(SessionFs::class)->listScreenshots($base);
        } catch (Throwable) {
            return [];
        }

        return array_map(
            fn (string $name): string => route('admin.runs.screenshot', ['run' => $record, 'name' => $name]),
            $names,
        );
    }

    /**
     * Transform the persisted web-search log into a RepeatableEntry-friendly
     * array of {query, urls} pairs — the query text plus its returned URLs
     * rendered as an annotated list (accepted vs. filtered-out and why), so
     * an operator can see what web search actually returned without reading
     * raw JSON.
     *
     * @return array<int, array{query: string, urls: string}>
     */
    private static function searchResultsDisplay(CollectionRun $record): array
    {
        $base = self::analysisBase($record);

        if ($base === null) {
            return [];
        }

        try {
            $log = app(SessionFs::class)->readSearchResults($base);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($log)) {
            return [];
        }

        $display = [];

        foreach ($log as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $query = trim((string) ($entry['query'] ?? ''));
            $source = $entry['source'] ?? null;
            $label = filled($source) ? "{$query}  [{$source}]" : ($query !== '' ? $query : '(no query)');

            $results = is_array($entry['results'] ?? null) ? $entry['results'] : [];
            $lines = [];

            foreach ($results as $result) {
                if (!is_array($result)) {
                    continue;
                }

                $url = (string) ($result['url'] ?? '');
                $domain = (string) ($result['domain'] ?? '');
                $status = ($result['accepted'] ?? false) === true
                    ? 'accepted'
                    : ('filtered: ' . (string) ($result['filtered'] ?? 'not accepted'));

                $lines[] = trim("- {$url}" . ($domain !== '' ? " ({$domain})" : '') . " — {$status}");
            }

            $display[] = [
                'query' => $label,
                'urls' => $lines === [] ? '(no results returned)' : implode("\n", $lines),
            ];
        }

        return $display;
    }

    private static function payloadMainMarkdown(CollectionRun $record): ?string
    {
        $base = self::analysisBase($record);

        if ($base === null) {
            return null;
        }

        try {
            return app(SessionFs::class)->readMainMarkdown($base);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Decode structure.json for a run, or null when absent/empty/unreadable.
     * Shared by the compact signals summary and the full-JSON entry so both
     * read the file only once per render.
     *
     * @return array<string, mixed>|null
     */
    private static function readStructureArray(CollectionRun $record): ?array
    {
        $base = self::analysisBase($record);

        if ($base === null) {
            return null;
        }

        try {
            $structure = app(SessionFs::class)->readStructure($base);
        } catch (Throwable) {
            return null;
        }

        return (is_array($structure) && $structure !== []) ? $structure : null;
    }

    /**
     * Pull a handful of flat, high-signal fields out of the (mostly nested)
     * structure.json into label => value pairs for a compact KeyValueEntry —
     * the full nested document is still available below as bounded JSON.
     *
     * @return array<string, string>
     */
    private static function structureSummaryDisplay(CollectionRun $record): array
    {
        $structure = self::readStructureArray($record);

        if ($structure === null) {
            return [];
        }

        $indicators = is_array($structure['company_indicators'] ?? null) ? $structure['company_indicators'] : [];
        $contact = is_array($indicators['contact_info'] ?? null) ? $indicators['contact_info'] : [];

        $summary = [];

        if (isset($structure['language'])) {
            $summary['Language'] = (string) $structure['language'];
        }

        if (isset($structure['page_type'])) {
            $summary['Page type'] = (string) $structure['page_type'];
        }

        if (isset($structure['structure_confidence'])) {
            $summary['Structure confidence'] = (string) $structure['structure_confidence'];
        }

        $summary['Emails found'] = self::joinOrDash($contact['emails'] ?? null);
        $summary['Phones found'] = self::joinOrDash($contact['phones'] ?? null);
        $summary['Social links'] = self::joinOrDash($indicators['social_links'] ?? null);

        return $summary;
    }

    private static function joinOrDash(mixed $value): string
    {
        if (!is_array($value) || $value === []) {
            return '-';
        }

        return implode(', ', array_map('strval', $value));
    }

    private static function payloadStructureJson(CollectionRun $record): ?string
    {
        $structure = self::readStructureArray($record);

        if ($structure === null) {
            return null;
        }

        return (string) json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Compact per-step summaries of the ReAct domain-discovery trace: the
     * model's reasoning text plus a short rendering of the tool call(s) it
     * made and what each tool returned (truncated — a page-visit tool
     * result can be many KB of markdown).
     *
     * @return array<int, array{step: string, text: string, tool_calls: string, tool_results: string}>
     */
    private static function reactTraceDisplay(CollectionRun $record): array
    {
        $base = self::analysisBase($record);

        if ($base === null) {
            return [];
        }

        try {
            $steps = app(SessionFs::class)->readReactTrace($base);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($steps)) {
            return [];
        }

        $display = [];

        foreach ($steps as $i => $step) {
            if (!is_array($step)) {
                continue;
            }

            $toolCalls = is_array($step['tool_calls'] ?? null) ? $step['tool_calls'] : [];
            $toolResults = is_array($step['tool_results'] ?? null) ? $step['tool_results'] : [];

            $callLines = [];
            foreach ($toolCalls as $call) {
                if (!is_array($call)) {
                    continue;
                }

                $name = (string) ($call['name'] ?? 'tool');
                $args = is_array($call['args'] ?? null)
                    ? (string) json_encode($call['args'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : '';

                $callLines[] = trim("{$name}({$args})");
            }

            $resultLines = [];
            foreach ($toolResults as $result) {
                if (!is_array($result)) {
                    continue;
                }

                $name = (string) ($result['name'] ?? 'tool');
                $text = (string) ($result['result'] ?? '');
                $resultLines[] = "{$name}: " . self::truncate($text, 500);
            }

            $display[] = [
                'step' => (string) ((int) $i + 1),
                'text' => (string) ($step['text'] ?? ''),
                'tool_calls' => $callLines === [] ? '-' : implode("\n", $callLines),
                'tool_results' => $resultLines === [] ? '-' : implode("\n\n", $resultLines),
            ];
        }

        return $display;
    }

    private static function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '… (truncated)' : $text;
    }

    /**
     * Whether any piece of the collected payload (search results, captured
     * page, structure, ReAct trace) is available for this run — used to
     * decide whether to show the "nothing captured" fallback message.
     */
    private static function hasAnyPayload(CollectionRun $record): bool
    {
        return self::searchResultsDisplay($record) !== []
            || self::payloadMainMarkdown($record) !== null
            || self::readStructureArray($record) !== null
            || self::reactTraceDisplay($record) !== [];
    }

    /**
     * Inline style for a bounded scrollable *container* (the outer list of a
     * RepeatableEntry payload block) so a run with many search queries or
     * ReAct steps can't push the page indefinitely tall.
     *
     * @return array{style: string}
     */
    private static function boundedListStyle(): array
    {
        return ['style' => 'display:block;max-height:26rem;overflow:auto'];
    }

    /**
     * Build a Langfuse deep-link to the run's trace session, or null when the
     * feature is disabled or any required piece (base URL, project id, session)
     * is missing. Fail-soft: never fabricates a broken URL.
     */
    private static function langfuseSessionUrl(?string $session): ?string
    {
        if (! config('services.langfuse.enabled')) {
            return null;
        }

        $base = (string) config('services.langfuse.base_url');
        $projectId = (string) config('services.langfuse.project_id');

        if ($base === '' || $projectId === '' || blank($session)) {
            return null;
        }

        return rtrim($base, '/') . "/project/{$projectId}/sessions/" . rawurlencode($session);
    }
}
