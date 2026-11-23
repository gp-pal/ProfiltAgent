<?php

namespace Tests\Unit;

use App\Tools\CompanyRegistryRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * The 5s statement cap that keeps a loaded registry from stalling /api/run also
 * killed the admin's gap-firm picker, whose predicate (no annotation, no email, no
 * phone, no mobile, ordered by employees) is an unavoidable full scan:
 * "1969 Query execution was interrupted (max_statement_time exceeded)".
 *
 * Exploratory, human-initiated queries therefore get their own longer budget, while
 * the latency-critical enrichment path keeps the short one.
 */
class CompanyRegistryExploratoryTimeoutTest extends TestCase
{
    public function test_exploratory_budget_is_longer_than_the_request_path_budget(): void
    {
        $this->assertGreaterThan(
            (int) config('company_registry.timeout_s'),
            (int) config('company_registry.exploratory_timeout_s'),
        );
    }

    public function test_gap_search_raises_then_restores_the_statement_timeout(): void
    {
        config(['company_registry.timeout_s' => 5, 'company_registry.exploratory_timeout_s' => 30]);

        $statements = [];
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$statements): bool {
            $statements[] = $sql;

            return true;
        });
        $connection->shouldReceive('table')->andReturn($this->emptyBuilder());
        DB::shouldReceive('connection')->with('company_registry')->andReturn($connection);

        (new CompanyRegistryRepository())->searchGapFirms([], 5);

        $this->assertSame(
            ['SET SESSION max_statement_time=30', 'SET SESSION max_statement_time=5'],
            $statements,
            'the long budget must be raised before the scan and restored after it',
        );
    }

    public function test_timeout_is_restored_even_when_the_query_fails(): void
    {
        config(['company_registry.timeout_s' => 5, 'company_registry.exploratory_timeout_s' => 30]);

        $statements = [];
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$statements): bool {
            $statements[] = $sql;

            return true;
        });
        // A timed-out scan throws; the session must not be left on the long budget,
        // or every later enrichment query would inherit it.
        $connection->shouldReceive('table')->andThrow(new \RuntimeException('max_statement_time exceeded'));
        DB::shouldReceive('connection')->with('company_registry')->andReturn($connection);

        try {
            (new CompanyRegistryRepository())->searchGapFirms([], 5);
            $this->fail('the query failure should surface');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('SET SESSION max_statement_time=5', end($statements));
    }

    private function emptyBuilder(): Builder
    {
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('whereNull', 'whereNotNull', 'where', 'orderByDesc', 'limit')->andReturnSelf();
        $builder->shouldReceive('get')->andReturn(collect());

        return $builder;
    }
}
