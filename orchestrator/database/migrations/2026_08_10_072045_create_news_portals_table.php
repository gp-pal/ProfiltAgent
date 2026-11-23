<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable whitelist of LT news portals whose mentions count toward the
 * profile's news dimension. Replaces the WebSearchFilters::NEWS_PORTALS const so
 * the list can change without a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_portals', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->unique();
            $table->string('label')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // Seed the list that lived in WebSearchFilters::NEWS_PORTALS, so moving the
        // whitelist into the DB is behaviour-neutral on deploy.
        $now = now();
        $rows = [
            ['delfi.lt', 'Delfi'], ['15min.lt', '15min'], ['lrytas.lt', 'Lrytas'],
            ['lrt.lt', 'LRT'], ['tv3.lt', 'TV3'], ['ve.lt', 'Vakarų ekspresas (ve.lt)'],
            ['diena.lt', 'Diena'], ['kauno.diena.lt', 'Kauno diena'], ['alfa.lt', 'Alfa'],
            ['bns.lt', 'BNS'], ['respublika.lt', 'Respublika'],
            ['vakaruekspresas.lt', 'Vakarų ekspresas'], ['madeinvilnius.lt', 'Made in Vilnius'],
            ['kmt.lt', 'KMT'],
        ];

        DB::table('news_portals')->insert(array_map(
            static fn (array $row): array => [
                'domain' => $row[0],
                'label' => $row[1],
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('news_portals');
    }
};
