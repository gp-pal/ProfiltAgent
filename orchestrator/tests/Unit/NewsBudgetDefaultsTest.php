<?php

namespace Tests\Unit;

use App\Services\NewsQueryBuilder;
use Tests\TestCase;

/**
 * Deliberately has no setUp() config overrides — NewsQueryBuilderTest rewrites
 * react.news.* for its own fixtures, so it can't also pin the shipped defaults.
 * This class reads config/react.php exactly as deployed.
 */
class NewsBudgetDefaultsTest extends TestCase
{
    public function test_the_shipped_defaults_research_exactly_one_person(): void
    {
        // Pins the arithmetic the config comments now state — the number
        // RequestConsole::peopleResearchBudget() shows the operator before a run starts.
        // A portal added at /admin/news-portals or a changed ceiling moves this —
        // deliberately loudly, by failing here.
        $this->assertSame(7, config('react.news.portals_per_query'));
        $this->assertSame(4, config('react.news.max_queries'));
        $this->assertSame(3, config('react.news.max_people'));
        $this->assertSame(1, NewsQueryBuilder::peopleBudget($this->seededPortals())); // 14 seeded portals
    }

    /**
     * Stand-ins for the 14 rows the news_portals seed migration ships. Only how many there
     * are decides the budget, and this test is deliberately database-free.
     *
     * @return array<int, string>
     */
    private function seededPortals(): array
    {
        return array_map(static fn (int $i): string => "portal{$i}.lt", range(1, 14));
    }
}
