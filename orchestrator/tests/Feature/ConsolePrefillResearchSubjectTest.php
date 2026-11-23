<?php

namespace Tests\Feature;

use App\Filament\Pages\RequestConsole;
use App\Models\CompanyProfile;
use App\Models\User;
use App\Services\CollectionResult;
use App\Services\CollectorService;
use App\Services\CompanyMatchJudge;
use App\Services\ContactsSource;
use App\Services\FieldRequirements;
use App\Services\NewsSource;
use App\Services\OfficialSiteSource;
use App\Services\OpenWebSource;
use App\Services\ProfileCoverageScorer;
use App\Services\RegistryFieldMapper;
use App\Services\CompanyRegistrySource;
use App\Services\RunRegistry;
use App\Tools\CompanyRegistryRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * The console and the collector wired to each other, on the one question neither side can
 * answer alone: which of a firm's directors actually gets researched.
 *
 * `react.news.max_queries` leaves room for ONE person per run, so the head of the people list
 * is the whole feature. Every piece here was individually correct and unit-tested when the
 * defect shipped: the console prefilled the registry's people, the collector ordered its own
 * people manager-first, and the merge let the caller win. Only the two of them together spent
 * the single research slot on a non-manager and stored `null / caller` over a role the registry
 * had already stated. So these tests
 * drive the real page into a real CollectorService and look at what the news phase is handed
 * and what the profile ends up holding — a test on either side alone reproduces the blind spot.
 *
 * Fixture is the observed firm: `firms.manager` names Tautrimas Girdvainis (the only
 * place a role is stated at all), while the manager tables list him LAST and role-less.
 */
class ConsolePrefillResearchSubjectTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '300000456';

    private const COMPANY = 'Baltijos siunta, AB';

    /**
     * The people list each collect() handed the news phase, one entry per run, in order.
     *
     * @var array<int, array<int, array{name: string, role: ?string}>>
     */
    private array $researched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindRegistry();
        $this->bindCollector();
    }

    public function test_the_prefilled_console_spends_the_research_slot_on_the_registry_manager(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->call('prefillFromRegistry', self::CODE)
            ->call('runRequest')
            ->assertSet('error', null);

        // Position 0 is not a detail, it IS the budget: with room for one person, whoever
        // leads this list is the only director the run ever asks about.
        $this->assertSame(
            ['Tautrimas Girdvainis', 'Rolandas Zabulis', 'Mindaugas Strakšas'],
            array_column($this->researched[0] ?? [], 'name'),
        );
    }

    public function test_prefilling_does_not_downgrade_a_role_the_registry_stated(): void
    {
        $this->actingAs($this->admin());

        $page = Livewire::test(RequestConsole::class)
            ->call('prefillFromRegistry', self::CODE)
            ->call('runRequest')
            ->assertSet('error', null);

        // The live symptom first: the stored profile held `Tautrimas Girdvainis / Vadovas`
        // before the prefilled run and `null` after it.
        $stored = array_column(CompanyProfile::query()->first()->fields['people'], null, 'name');
        $this->assertSame('Vadovas', $stored['Tautrimas Girdvainis']['role'] ?? null);

        // Then the boundary the loss happened at: the form is where the console's copy of the
        // registry's answer is made, and a null role here is the caller stating nothing.
        $this->assertSame(
            [
                ['name' => 'Tautrimas Girdvainis', 'role' => 'Vadovas'],
                ['name' => 'Rolandas Zabulis', 'role' => null],
                ['name' => 'Mindaugas Strakšas', 'role' => null],
            ],
            $page->get('data')['people'],
        );
    }

    public function test_the_console_and_a_bare_caller_research_the_same_people_in_the_same_order(): void
    {
        $this->actingAs($this->admin());

        // What a caller that knows no directors sends — the collector's own ordering.
        app(CollectorService::class)->collect([
            'name' => self::COMPANY,
            'code' => self::CODE,
            'domain' => 'post.lt',
        ]);

        Livewire::test(RequestConsole::class)
            ->call('prefillFromRegistry', self::CODE)
            ->call('runRequest')
            ->assertSet('error', null);

        $this->assertCount(2, $this->researched, 'both runs must have reached the news phase');

        // Agreement alone is not a guard: two runs that both researched NOBODY agree perfectly.
        // So the outcome has to be non-empty AND led by the manager before the comparison below
        // means anything.
        $this->assertSame(
            ['Tautrimas Girdvainis', 'Rolandas Zabulis', 'Mindaugas Strakšas'],
            array_column($this->researched[0], 'name'),
            'the bare run must research the registry manager first',
        );

        // Name and role only: that people prefilled from the registry are labelled `caller`
        // rather than `registry_db` is a separate, recorded provenance issue, and pinning it
        // here would tie this test to a rule it is not about.
        $this->assertSame(
            $this->researched[0],
            $this->researched[1],
            'the console must not order the research queue differently from the collector',
        );
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'prefill@profiltagent.example',
            'password' => 'password123',
        ]);
    }

    private function bindRegistry(): void
    {
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('searchGapFirms')->andReturn([]);
        $repo->shouldReceive('factsForCode')->with(self::CODE)->andReturn([
            'code' => self::CODE,
            'name' => self::COMPANY,
            'site' => 'post.lt',
            'address' => 'Pavyzdžio g. 3, LT-01100 Vilnius',
            'employees' => 3390,
            'manager' => 'Tautrimas Girdvainis',
        ]);
        $repo->shouldReceive('peopleForCode')->with(self::CODE)->andReturn([
            ['name' => 'Rolandas Zabulis', 'role' => null],
            ['name' => 'Mindaugas Strakšas', 'role' => null],
            ['name' => 'Tautrimas Girdvainis', 'role' => null],
        ]);

        $this->app->instance(CompanyRegistryRepository::class, $repo);
    }

    /**
     * A real CollectorService over the real registry source and the real merge, with only the
     * web sources and the news search stubbed: the interaction under test is registry people ->
     * merge -> news target, and every link in it has to be the shipped code.
     */
    private function bindCollector(): void
    {
        $judge = Mockery::mock(CompanyMatchJudge::class);
        $judge->shouldReceive('judge')->andReturn(['verdict' => 'pass', 'match_confidence' => 0.9, 'data_coverage' => 0.8, 'reasons' => []]);
        $judge->shouldReceive('rejects')->andReturn(false);

        $news = Mockery::mock(NewsSource::class);
        $news->shouldReceive('name')->andReturn('news');
        $news->shouldReceive('supports')->andReturn(true);
        $news->shouldReceive('collect')->andReturnUsing(function (array $target): CollectionResult {
            $this->researched[] = array_map(
                static fn (array $person): array => [
                    'name' => (string) $person['name'],
                    'role' => $person['role'] ?? null,
                ],
                (array) ($target['people'] ?? []),
            );

            return CollectionResult::none('news', 'empty');
        });

        $this->app->instance(CollectorService::class, new CollectorService(
            new RunRegistry(),
            new ProfileCoverageScorer(),
            $judge,
            new FieldRequirements(),
            new CompanyRegistrySource(app(CompanyRegistryRepository::class), new RegistryFieldMapper()),
            $this->silent(OfficialSiteSource::class, 'official_site'),
            $this->silent(OpenWebSource::class, 'open_web'),
            $this->silent(ContactsSource::class, 'contacts'),
            $news,
        ));
    }

    /**
     * @param  class-string  $class
     */
    private function silent(string $class, string $name): mixed
    {
        $source = Mockery::mock($class);
        $source->shouldReceive('name')->andReturn($name);
        $source->shouldReceive('supports')->andReturn(false);

        return $source;
    }
}
