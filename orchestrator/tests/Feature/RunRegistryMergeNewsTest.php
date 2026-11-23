<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Services\RunRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunRegistryMergeNewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_news_adds_news_without_touching_coverage(): void
    {
        $registry = new RunRegistry();
        $target = ['code' => '111', 'name' => 'Acmena'];

        // Seed a coverage profile first (like the ladder would).
        $registry->mergeProfile($target, ['address' => 'Vilnius'], ['address' => 1.0], ['address' => 'registry_db']);
        $before = CompanyProfile::where('company_key', 'code:111')->firstOrFail();
        $scoreBefore = $before->overall_score;
        $coverageBefore = $before->field_coverage;

        $news = ['score' => 0.667, 'fresh' => true, 'mentions' => [
            ['portal' => 'delfi.lt', 'title' => 't', 'url' => 'https://delfi.lt/a', 'date' => '2026-05-01'],
        ]];
        $registry->mergeNews($target, $news);

        $after = CompanyProfile::where('company_key', 'code:111')->firstOrFail();
        $this->assertSame($news, $after->fields['news']);
        $this->assertSame('Vilnius', $after->fields['address']);        // untouched
        $this->assertSame($scoreBefore, $after->overall_score);         // unchanged by news
        $this->assertSame($coverageBefore, $after->field_coverage);     // unchanged by news
        $this->assertArrayNotHasKey('news', $after->field_coverage ?? []);
    }
}
