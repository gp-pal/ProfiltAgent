<?php

namespace App\Filament\Pages;

use App\Models\CompanyProfile;
use App\Models\ProfileField;
use App\Services\CollectorService;
use App\Services\FieldRequirements;
use App\Services\NewsPortalRegistry;
use App\Services\NewsQueryBuilder;
use App\Services\RegistryFieldMapper;
use App\Tools\RegistryPeople;
use App\Tools\CompanyRegistryRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * Compose a /api/run request by hand and see the whole result, so the two cases that
 * matter can be compared side by side: what the pipeline does when the calling agent
 * supplies data, and what it does when it supplies nothing.
 *
 * Deliberately synchronous, unlike the queued CollectCompanyJob: a test console that answers
 * "queued" is useless. A run takes roughly 30-90s, so the page holds the request open
 * for that long — acceptable for an admin tool, and the reason this is not the path
 * production traffic takes.
 */
class RequestConsole extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Request console';

    protected static ?string $title = 'Request console';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.request-console';

    /** @var array<string, mixed> */
    public array $data = [
        'company' => '',
        'code' => '',
        'domain' => '',
        'fields' => [],
        'known_fields' => [],
        'suggestions' => [],
        'people' => [],
    ];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public ?string $error = null;

    public ?float $elapsed = null;

    /** @var array{q: string, site: string, size: string, status: string} */
    public array $filters = ['q' => '', 'site' => 'any', 'size' => 'any', 'status' => 'any'];

    public bool $registryUnreachable = false;

    /** False when no external registry is configured at all (as opposed to unreachable). */
    public bool $registryConfigured = true;

    public function mount(): void
    {
        $this->data['fields'] = app(FieldRequirements::class)->resolve([]);
        // Decided here, not only in loadRecords(): the view leaves the whole registry
        // block out when nothing is configured, so the table never renders to set it.
        $this->registryConfigured = $this->registryIsConfigured();
    }

    private function registryIsConfigured(): bool
    {
        return trim((string) config('company_registry.connection.host')) !== '';
    }

    public function search(): void
    {
        $this->resetTable();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Company')
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 3])
                            ->schema([
                                TextInput::make('company')
                                    ->label('Name')
                                    ->required(),
                                TextInput::make('code')
                                    ->label('Company code')
                                    ->helperText('With a code the registry lookup is indexed; without one it scans the table.'),
                                TextInput::make('domain')
                                    ->label('Domain')
                                    ->helperText('When given, domain resolution is skipped.'),
                            ]),
                    ]),

                Section::make('What we ask for')
                    ->description('What a sales agent would send: which fields are needed.')
                    ->schema([
                        Select::make('fields')
                            ->label('Declared fields')
                            ->multiple()
                            ->native(false)
                            ->options(fn (): array => ProfileField::query()
                                ->where('enabled', true)
                                ->orderBy('sort_order')
                                ->pluck('label', 'key')
                                ->all())
                            ->helperText('An empty list = the required fields of the catalogue.')
                            // Live: the "nedeklaruotas" helper text on each suggestion row
                            // depends on this list, and must stop warning the moment a field
                            // is declared — not only after the request is submitted.
                            ->live(),
                        // The way out of a permanent 'no verification record': for an already
                        // enriched firm the registry alone fills every declared field, so the
                        // ladder stops at the first source and no source ever re-confirms the
                        // stored values. Off by default — it spends a full ladder on data we
                        // already hold.
                        Checkbox::make('verify_fields')
                            ->label('Verify the match of fields already collected')
                            // "Gali užimti", not "užima": the pass is BOUNDED by
                            // ladder_budget_s, not a consumer of it — it can also finish at
                            // once. The outage sentence is here because the alternative is an
                            // operator watching a full ladder change nothing and concluding
                            // the checkbox is broken: corroboration needs a verified
                            // confirmer, so with the judge down nothing can be established.
                            ->helperText('Lets the sources confirm fields already collected, even when nothing new is left to collect. May take the whole ladder time budget. When the judge is unreachable the check changes nothing — worth repeating later.'),
                    ]),

                Section::make('Registry suggestions')
                    ->description('Facts taken from the registry. Checked ones are sent as `supplied` and are not searched for; unchecked ones stay a gap the ladder fills itself.')
                    ->visible(fn (): bool => ($this->data['suggestions'] ?? []) !== [])
                    ->schema([
                        Repeater::make('suggestions')
                            // ->label('') does not suppress Filament's default humanized
                            // label (an empty string is not `filled()`, so it falls back
                            // to "Suggestions") — hiddenLabel() is the real way to hide it.
                            // The Section above already carries the heading.
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(true)
                            ->reorderable(false)
                            ->schema([
                                Grid::make(['default' => 1, 'sm' => 3])
                                    ->schema([
                                        TextInput::make('key')->label('Field')->disabled(),
                                        TextInput::make('value')->label('Value'),
                                        Checkbox::make('known')
                                            ->label('Treat as known')
                                            // Live: ticking the box must re-evaluate the
                                            // helper text below immediately, not only once
                                            // the request is submitted.
                                            ->live()
                                            // The declared list is NOT amended silently:
                                            // changing the declaration behind the operator
                                            // is the same lie removed from three other
                                            // places. Say it instead.
                                            ->helperText(fn (Get $get): ?string => in_array(
                                                (string) $get('key'),
                                                $this->declaredFields(),
                                                true,
                                            ) ? null : 'nedeklaruotas — nebus panaudotas'),
                                    ]),
                            ]),
                    ]),

                Section::make('What we already have')
                    ->description('Optional. Data the caller sent. It is recorded as `supplied` and is not searched for.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('known_fields')
                            ->label('Known values')
                            ->addActionLabel('Add field')
                            ->defaultItems(0)
                            ->schema([
                                Grid::make(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        // A select, not free text: an unknown key is dropped
                                        // silently downstream, so a typo used to look exactly
                                        // like a field the pipeline could not fill. Here a
                                        // wrong key cannot be entered at all, and the type is
                                        // visible next to the name.
                                        Select::make('key')
                                            ->label('Field')
                                            ->required()
                                            ->native(false)
                                            ->options(fn (): array => $this->fieldKeyOptions()),
                                        TextInput::make('value')
                                            ->label('Value')
                                            ->required()
                                            ->helperText('For list fields, separate several values with commas.'),
                                    ]),
                            ]),
                    ]),

                Section::make('People to research')
                    ->description('Optional. Directors and executives whose news is searched for. A manager found in the registry is researched as well, even if not entered here.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('people')
                            ->label('Known people')
                            ->addActionLabel('Add person')
                            // The one number the form used to hide: the repeater accepts
                            // any number of names while the query ceiling researches the
                            // first few. An operator who lists a board of five deserves to
                            // know that before the run, not never — and to know which END of
                            // the list the ceiling spends itself on, since moving a name to
                            // the top is choosing who gets researched.
                            ->helperText(fn (): string => 'Limit on people whose news is searched for: '
                                . $this->peopleResearchBudget()
                                . '. The head of the list is searched; the rest are stored on the profile but not searched for.')
                            ->schema([
                                Grid::make(['default' => 1, 'sm' => 2])
                                    ->schema([
                                        TextInput::make('name')->label('Full name')->required(),
                                        TextInput::make('role')->label('Role'),
                                    ]),
                            ])
                            ->defaultItems(0),
                    ]),
            ]);
    }

    /**
     * Filter form (search/site/size/status), rendered above the table as a
     * Filament-native responsive grid. Bound directly to $filters via
     * statePath, so it behaves exactly like the plain wire:model inputs it
     * replaces.
     */
    public function filterForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        TextInput::make('q')
                            ->label('Name or code'),
                        // native(false): render Filament's own JS-anchored dropdown
                        // instead of a native <select>. Chrome mis-positions the
                        // native option popup for appearance:none selects (it
                        // "jumped to the side"); the Filament panel anchors under
                        // the field and matches the dark theme.
                        Select::make('site')
                            ->label('Website')
                            ->native(false)
                            ->options([
                                'any' => 'Any',
                                'with' => 'Has a website',
                                'without' => 'Has no website',
                            ]),
                        Select::make('size')
                            ->label('Size')
                            ->native(false)
                            ->options([
                                'any' => 'Any',
                                'small' => 'Small (<10)',
                                'medium' => 'Medium (10–100)',
                                'large' => 'Large (>100)',
                            ]),
                        Select::make('status')
                            ->label('Enrichment')
                            ->native(false)
                            ->options([
                                'any' => 'Any',
                                'not_enriched' => 'Not enriched',
                                'poor' => 'Poor (<0.70)',
                            ]),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int|string $page, int|string $recordsPerPage): LengthAwarePaginator {
                $firms = $this->loadRecords();
                $perPage = max(1, (int) $recordsPerPage);
                $currentPage = max(1, (int) $page);

                return new LengthAwarePaginator(
                    items: $firms->forPage($currentPage, $perPage),
                    total: $firms->count(),
                    perPage: $perPage,
                    currentPage: $currentPage,
                    options: ['pageName' => $this->getTablePaginationPageName()],
                );
            })
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('name')->label('Name')->searchable(false)->wrap(),
                TextColumn::make('code')->label('Code')->fontFamily(FontFamily::Mono),
                TextColumn::make('site')->label('Website')->placeholder('-')->limit(40),
                TextColumn::make('address')
                    ->label('City / address')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('employees')
                    ->label('Employees')
                    ->numeric()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Enrichment')
                    ->badge()
                    ->formatStateUsing(fn (string $state, array $record): string => $state === 'enriched'
                        ? number_format((float) $record['score'], 2)
                        : 'not enriched')
                    ->color(fn (string $state, array $record): string => $state === 'not_enriched'
                        ? 'gray'
                        : match (true) {
                            $record['score'] >= 0.7 => 'success',
                            $record['score'] >= 0.5 => 'warning',
                            default => 'danger',
                        }),
            ])
            ->recordActions([
                Action::make('prefill')
                    ->label('Fill')
                    ->icon(Heroicon::OutlinedArrowDownOnSquare)
                    ->action(fn (array $record): mixed => $this->prefillFromRegistry((string) ($record['code'] ?? ''))),
            ])
            ->emptyStateHeading('No companies match these filters.');
    }

    /**
     * Fill the request form from the registry.
     *
     * Facts arrive as SUGGESTIONS, every one unchecked: the operator decides what counts
     * as already known, and whatever stays unchecked remains a gap for the ladder to fill.
     * Prefilling everything as known would make each run look `sufficient` while scraping
     * nothing.
     *
     * A successful prefill describes exactly ONE firm: the suggestions AND the manually
     * typed known_fields rows are both replaced, since either surviving would mix the
     * previous firm's facts into this firm's request. Discarded rows are announced.
     *
     * Fail-soft throughout, because the registry is external and not always reachable: a failed lookup
     * costs a warning, never the form the operator already filled in.
     */
    public function prefillFromRegistry(string $code): void
    {
        $code = trim($code);
        if ($code === '') {
            Notification::make()->title('Company has no registry code')->warning()->send();

            return;
        }

        try {
            $facts = app(CompanyRegistryRepository::class)->factsForCode($code);
        } catch (Throwable $e) {
            Log::warning('Console prefill failed', ['code' => $code, 'error' => $e->getMessage()]);
            Notification::make()->title('Registry unreachable')->body($e->getMessage())->warning()->send();

            return;
        }

        if ($facts === null) {
            Notification::make()->title("No such company in the registry: {$code}")->warning()->send();

            return;
        }

        $peopleLookupFailed = false;
        try {
            $people = app(CompanyRegistryRepository::class)->peopleForCode($code);
        } catch (Throwable $e) {
            Log::warning('Console prefill people failed', ['code' => $code, 'error' => $e->getMessage()]);
            Notification::make()->title('Registry unreachable')->body($e->getMessage())->warning()->send();
            $people = [];
            $peopleLookupFailed = true;
        }

        $this->data['company'] = (string) ($facts['name'] ?? '');
        $this->data['code'] = (string) ($facts['code'] ?? $code);
        $this->data['domain'] = (string) ($facts['site'] ?? '');

        // Not the console's order to choose. Order IS the person-research budget (one person
        // per run), and this page used to list peopleForCode() as it came — skipping the
        // manager column entirely, so the console spent that single slot on a non-manager and
        // supplied a null role for the one person whose role the registry states. Both call
        // sites now read the same rule, which is what keeps them from drifting apart again.
        $this->data['people'] = RegistryPeople::ordered($facts['manager'] ?? null, $people);

        // Replace, never append: appending would mix two companies' facts into one request.
        $this->data['suggestions'] = [];
        foreach (app(RegistryFieldMapper::class)->fields($facts) as $key => $value) {
            $this->data['suggestions'][] = [
                'key' => (string) $key,
                'value' => is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value,
                'known' => false,
            ];
        }

        // Clear, don't carry. composeKnownFields() merges the manual rows ON TOP of the
        // suggestions, so a row typed for the previous firm would beat this firm's own
        // registry value and ship as its fact — recorded with `caller` provenance and
        // authoritative: true, which no later source and no later run can correct. Nothing
        // on screen ties a row to the firm it was typed for, so the operator cannot see it
        // happening. Clearing costs at most a re-type; carrying costs a corrupted profile.
        $clearedRows = count((array) ($this->data['known_fields'] ?? []));
        $this->data['known_fields'] = [];

        if ($clearedRows > 0) {
            // Its own notification, not a line in the success body below: the operator must
            // be told even when the people lookup fails and that success message is
            // withheld. Taking away typed work silently is the one thing worse than clearing.
            Notification::make()
                ->title('Manually entered values cleared')
                ->body('Fields: ' . $clearedRows)
                ->warning()
                ->send();
        }

        // Identity and suggestions are good data even when the people lookup failed;
        // only the success message is withheld, so the operator isn't told "done" over
        // a request that partially failed.
        if ($peopleLookupFailed) {
            return;
        }

        Notification::make()
            ->title('Filled from the registry')
            ->body('Suggestions: ' . count($this->data['suggestions']))
            ->success()
            ->send();
    }

    /**
     * Load candidates from the registry (fail-soft), join local scores, apply the
     * status filter, and return a Collection keyed by firm code.
     *
     * Not a cache for prefillFromRegistry(): searchGapFirms() projects only
     * name/code/site/address/employees, while factsForCode() also returns the contact
     * fields the suggestions are built from. Serving a prefill from these rows would
     * silently produce suggestion sets with every contact field missing.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function loadRecords(): Collection
    {
        // No registry configured is a deployment choice, not an outage: say so, and do
        // not attempt a connection that can only fail.
        $this->registryConfigured = $this->registryIsConfigured();
        if (! $this->registryConfigured) {
            $this->registryUnreachable = false;

            return collect();
        }

        try {
            $firms = app(CompanyRegistryRepository::class)->searchGapFirms([
                'q' => $this->filters['q'],
                'site' => $this->filters['site'],
                'size' => $this->filters['size'],
            ]);
            $this->registryUnreachable = false;
        } catch (Throwable) {
            $this->registryUnreachable = true;

            return collect();
        }

        $scores = CompanyProfile::scoresByCode(array_column($firms, 'code'));

        return collect($firms)
            ->map(function (array $firm) use ($scores): array {
                $hasProfile = array_key_exists($firm['code'], $scores);
                $firm['score'] = $hasProfile ? $scores[$firm['code']] : null;
                $firm['status'] = $hasProfile ? 'enriched' : 'not_enriched';

                return $firm;
            })
            ->reject(function (array $firm): bool {
                return match ($this->filters['status']) {
                    'not_enriched' => $firm['status'] !== 'not_enriched',
                    'poor' => ! ($firm['status'] === 'enriched' && $firm['score'] < 0.7),
                    default => false,
                };
            })
            ->keyBy('code');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')
                ->label('Run request')
                ->icon(Heroicon::OutlinedPlay)
                ->action('runRequest'),

            Action::make('clear')
                ->label('Clear result')
                ->color('gray')
                ->visible(fn (): bool => $this->result !== null || $this->error !== null)
                ->action('clearResult'),
        ];
    }

    public function clearResult(): void
    {
        $this->result = null;
        $this->error = null;
        $this->elapsed = null;
    }

    public function runRequest(): void
    {
        $this->clearResult();

        $company = trim((string) ($this->data['company'] ?? ''));
        if ($company === '') {
            Notification::make()->title('Enter a company name')->danger()->send();

            return;
        }

        // A collection run outlives PHP's default 30s: the ladder alone is budgeted at
        // REACT_LADDER_BUDGET_S (120s) and the slowest single source can add to that.
        // Without this the request dies mid-run with a FatalError that no catch below
        // can see, and the page 500s instead of showing the result.
        // Note this cannot beat php-fpm's own request_terminate_timeout, which PHP
        // cannot raise from inside the request.
        set_time_limit((int) config('react.coverage.ladder_budget_s', 120) * 3);

        $started = microtime(true);

        try {
            $this->result = app(CollectorService::class)->collect([
                'name' => $company,
                'code' => $this->blankToNull($this->data['code'] ?? null),
                'domain' => $this->blankToNull($this->data['domain'] ?? null),
                'people' => $this->normalizePeople($this->data['people'] ?? []),
                'fields' => $this->requestedFields(),
                'known_fields' => $this->composeKnownFields(),
                'session' => (string) Str::uuid(),
                'country' => 'LT',
                'verify_fields' => (bool) ($this->data['verify_fields'] ?? false),
            ]);
        } catch (Throwable $e) {
            // The console must show the failure, not a blank page: a broken run is
            // exactly what we are here to look at.
            $this->error = $e->getMessage();
            Log::warning('Request console run failed', ['company' => $company, 'error' => $e->getMessage()]);
            Notification::make()->title('Request failed')->body($e->getMessage())->danger()->send();
        }

        $this->elapsed = round(microtime(true) - $started, 1);

        if ($this->error === null) {
            Notification::make()
                ->title("Finished in {$this->elapsed}s")
                ->body('Verdict: ' . ($this->result['verdict'] ?? '?'))
                ->success()
                ->send();
        }
    }

    /**
     * The `fields[]` list exactly as the operator declared it: reindexed, blanks dropped,
     * otherwise untouched. This is what the payload carries, because it is what a real
     * caller sends — including the empty list, the one case this page exists to compare.
     * Resolving before sending would make the console unable to exercise that case at all.
     *
     * @return array<int, string>
     */
    private function requestedFields(): array
    {
        return array_values(array_filter((array) ($this->data['fields'] ?? [])));
    }

    /**
     * The declared set as the pipeline will actually see it, resolved through the same
     * {@see FieldRequirements} that CollectorService::collect() resolves it through.
     *
     * Not the console's rules to reimplement, and the two that bite are invisible from
     * here: an empty list means the catalogue's required fields (never "nothing declared"),
     * and a key outside the enabled catalogue is dropped and falls back to that same
     * default. Computing this locally is what let the "nedeklaruotas" warning tell the
     * operator a field would be ignored while the collector declared it and recorded the
     * ticked value as authoritative.
     *
     * @return array<int, string>
     */
    private function declaredFields(): array
    {
        return app(FieldRequirements::class)->resolve($this->requestedFields());
    }

    /**
     * Catalogue keys the caller may supply, labelled with the type each expects so a list
     * field is obvious before anything is typed.
     *
     * @return array<string, string>
     */
    private function fieldKeyOptions(): array
    {
        return ProfileField::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->get(['key', 'label', 'type'])
            ->mapWithKeys(static fn (ProfileField $f): array => [
                $f->key => $f->key . ' — ' . $f->label . ($f->type === 'array' ? ' (list)' : ''),
            ])
            ->all();
    }

    /**
     * How many people this run will actually search for. The helper answers by asking
     * NewsQueryBuilder::build() — the code that plans the run — so the number on screen
     * cannot drift from the one the pipeline honours. The portal list is local and cached,
     * and is passed whole rather than counted: how the plan depends on it is build()'s
     * business, not this page's. An empty list is a real answer (no portals), not a failure.
     */
    private function peopleResearchBudget(): int
    {
        return NewsQueryBuilder::peopleBudget(app(NewsPortalRegistry::class)->enabledDomains());
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  mixed  $people
     * @return array<int, array{name: string, role: ?string}>
     */
    private function normalizePeople(mixed $people): array
    {
        $out = [];

        foreach ((array) $people as $person) {
            $name = trim((string) (is_array($person) ? ($person['name'] ?? '') : ''));
            if ($name === '') {
                continue;
            }

            $role = trim((string) (is_array($person) ? ($person['role'] ?? '') : ''));
            $out[] = ['name' => $name, 'role' => $role === '' ? null : $role];
        }

        return $out;
    }

    /**
     * The values the caller declares it already holds: checked suggestions first, then the
     * manually added rows on top.
     *
     * A manual row wins for a field that both name, because a person typed it deliberately
     * while the suggestion is only what the registry happened to hold. An unchecked
     * suggestion contributes nothing at all — that is what keeps it a gap for the ladder.
     *
     * @return array<string, string>
     */
    private function composeKnownFields(): array
    {
        $out = [];

        foreach ((array) ($this->data['suggestions'] ?? []) as $row) {
            if (!is_array($row) || ($row['known'] ?? false) !== true) {
                continue;
            }

            $key = trim((string) ($row['key'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));

            if ($key !== '' && $value !== '') {
                $out[$key] = $value;
            }
        }

        return array_merge($out, $this->normalizeKnownFields($this->data['known_fields'] ?? []));
    }

    /**
     * Flatten the repeater's rows into the key => value map the collector expects.
     *
     * Filament keys repeater items by uuid, so the outer keys are meaningless here — only
     * each row's own `key` matters. A later row with the same field overwrites an earlier
     * one, which is what editing the same field twice looks like to the person typing.
     *
     * @param  mixed  $known
     * @return array<string, string>
     */
    private function normalizeKnownFields(mixed $known): array
    {
        $out = [];

        foreach ((array) $known as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['key'] ?? ''));
            $value = trim((string) (is_scalar($row['value'] ?? null) ? $row['value'] : ''));

            if ($key !== '' && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
