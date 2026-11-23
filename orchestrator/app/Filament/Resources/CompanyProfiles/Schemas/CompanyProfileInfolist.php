<?php

namespace App\Filament\Resources\CompanyProfiles\Schemas;

use App\Filament\Support\NewsSearchNotice;
use App\Models\CollectionRun;
use App\Models\CompanyProfile;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class CompanyProfileInfolist
{
    /**
     * Human labels for the well-known collected-profile field keys; anything
     * else falls back to a title-cased version of its snake_case key.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'name' => 'Name',
        'description' => 'Description',
        'activity' => 'Activity',
        'address' => 'Address',
        'emails' => 'Emails',
        'phones' => 'Phones',
        'social_links' => 'Social links',
        'code' => 'Registry code',
        'city' => 'City',
        'country' => 'Country',
        'legal_form' => 'Legal form',
        'employees' => 'Employees',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Single top-level column so sections stack full-width, not two-across.
            ->columns(1)
            ->components([
                Section::make('Overview')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('company_key'),
                        TextEntry::make('company_code')
                            ->placeholder('-'),
                        TextEntry::make('domain')
                            ->placeholder('-'),
                        TextEntry::make('overall_score')
                            ->numeric(decimalPlaces: 2),
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ]),
                Section::make('Fields')
                    ->description('Accumulated best-known profile fields — description, activity, contacts, address, social.')
                    ->schema([
                        KeyValueEntry::make('fields')
                            ->hiddenLabel()
                            ->keyLabel('Field')
                            ->valueLabel('Value')
                            ->state(fn (CompanyProfile $record): array => self::fieldsDisplay($record->fields))
                            ->columnSpanFull(),
                    ]),
                Section::make('Field coverage')
                    ->description('Per-field confidence score (0..1).')
                    ->schema([
                        KeyValueEntry::make('field_coverage')
                            ->hiddenLabel()
                            ->keyLabel('Field')
                            ->valueLabel('Coverage')
                            ->columnSpanFull(),
                    ]),
                Section::make('Field sources')
                    ->description('Per-field provenance — where each value came from, and whether that source was verified as this company.')
                    ->schema([
                        KeyValueEntry::make('field_sources')
                            ->hiddenLabel()
                            ->keyLabel('Field')
                            ->valueLabel('Source')
                            ->state(fn (CompanyProfile $record): array => self::sourcesDisplay($record))
                            ->columnSpanFull(),
                    ]),
                // NOT collapsible: Filament's collapsible sections use Alpine
                // x-collapse, which measures content height at init and leaves the
                // body overflow:hidden — a ->state() HtmlString that settles later
                // can get clipped to ~0 height. The other profile sections aren't
                // collapsible either, so this stays consistent. The entry is a
                // bounded, self-scrolling box (overriding .fi-in-text's own
                // overflow:hidden) so a firm with many mentions fits the page
                // height instead of sprawling / being clipped.
                Section::make('News mentions')
                    ->description('Third-party LT news-portal mentions (separate from the coverage score).')
                    ->schema([
                        TextEntry::make('news_block')
                            ->hiddenLabel()
                            ->state(fn (CompanyProfile $record): HtmlString => self::newsBlock($record->fields['news'] ?? null, self::lastNewsSearchFailure($record)))
                            ->columnSpanFull()
                            ->extraAttributes(['style' => 'display:block;max-height:24rem;overflow-y:auto'])
                            ->html(),
                    ]),
            ]);
    }

    /**
     * Field label => the source that contributed the stored value, with what is known
     * about that source's match verification. A TextEntry cannot show this: handed an
     * associative array it renders the values alone, so the page listed sources with
     * no field beside them.
     *
     * @return array<string, string>
     */
    private static function sourcesDisplay(CompanyProfile $record): array
    {
        $display = [];

        foreach ((array) ($record->field_sources ?? []) as $field => $source) {
            $label = self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', (string) $field));
            $source = trim((string) $source);
            $status = $record->verificationOf((string) $field)['status'];

            $display[$label] = ($source === '' ? '-' : $source) . ' · ' . $status;
        }

        return $display;
    }

    /**
     * Why the most recent run's news search could not see, or null when it could (or
     * never ran). Mentions are only ever merged into the profile, so a failed search
     * leaves the stored list untouched — and an empty list then read as "nobody wrote
     * about this company" on a run that never reached a portal.
     */
    private static function lastNewsSearchFailure(CompanyProfile $record): ?string
    {
        $run = CollectionRun::query()
            ->where('company_key', $record->company_key)
            ->latest('started_at')
            ->latest('id')
            ->first();

        $action = $run?->actions->firstWhere('source', 'news');

        return $action === null ? null : NewsSearchNotice::forAction((string) $action->status, $action->detail);
    }

    /**
     * Turn the accumulated `fields` blob (description/activity/emails/
     * phones/address/social_links/…) into label => readable-string pairs for
     * a KeyValueEntry, instead of a raw JSON dump. List values (emails,
     * phones, social_links) are joined into a comma-separated string.
     *
     * @return array<string, string>
     */
    private static function fieldsDisplay(mixed $fields): array
    {
        if (! is_array($fields) || $fields === []) {
            return [];
        }

        $display = [];

        foreach ($fields as $key => $value) {
            // 'news' is a separate dimension (nested array) rendered in its own
            // section — skip it here so it doesn't break the scalar key-value table.
            if ($key === 'news') {
                continue;
            }
            $label = self::FIELD_LABELS[$key] ?? ucfirst(str_replace('_', ' ', (string) $key));
            $display[$label] = self::scalarize($value);
        }

        return $display;
    }

    /**
     * Reduce a field value to a single displayable string; arrays (emails,
     * phones, social_links) are comma-joined, blanks become a dash.
     */
    private static function scalarize(mixed $value): string
    {
        if (is_array($value)) {
            return $value === [] ? '-' : implode(', ', array_map('strval', $value));
        }

        if ($value === null || $value === '') {
            return '-';
        }

        return (string) $value;
    }

    /**
     * Render the news dimension (score + freshness + mentions) as a self-contained
     * escaped HTML block. Scraped titles/urls MUST be escaped (->html() renders the
     * HtmlString verbatim). Returns a placeholder when there is no news.
     *
     * @param array{score?: float, fresh?: bool, mentions?: array<int, array<string, mixed>>}|null $news
     * @param ?string $searchFailure why the latest run's news search could not see, if it could not
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
        foreach ($mentions as $m) {
            $portal = e((string) ($m['portal'] ?? ''));
            $title = e((string) ($m['title'] ?? ''));
            $url = e((string) ($m['url'] ?? ''));
            $date = e((string) ($m['date'] ?? ''));
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
}
