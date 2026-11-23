<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable catalogue of the fields a company profile should contain.
 * Replaces ProfileCoverageScorer::FIELDS as the definition of "complete", so the
 * consumer's requirements — not ProfiltAgent's own constant — decide what a run must
 * fill. A field can be added or retired without a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_fields', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('type')->default('string');
            $table->string('group')->nullable();
            $table->text('extraction_hint')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // The six legacy fields stay `required` so a deploy changes nothing: every
        // existing consumer reads exactly these. The new ones are enabled but not
        // required — a company that genuinely has no `brands` must not make every
        // run report `partial`.
        $now = now();
        $rows = [
            ['description', 'Description', 'text', 'Core', 'Trumpas įmonės aprašymas jos pačios žodžiais.', true, 10],
            ['activity', 'Activity', 'text', 'Core', 'Kuo įmonė užsiima / jos sektorius.', true, 20],
            ['emails', 'Emails', 'array', 'Core', 'Visi rasti el. pašto adresai.', true, 30],
            ['phones', 'Phones', 'array', 'Core', 'Visi rasti telefono numeriai.', true, 40],
            ['address', 'Address', 'string', 'Core', 'Fizinis adresas.', true, 50],
            ['social_links', 'Social links', 'array', 'Core', 'Socialinių tinklų ir kitų savų platformų nuorodos.', true, 60],
            ['products_services', 'Products and services', 'array', 'What they sell', 'Konkretūs produktai ar paslaugos, kuriuos įmonė siūlo. Vardink taip, kaip pavadinta puslapyje.', false, 70],
            ['customer_segments', 'Customer segments', 'array', 'What they sell', 'Kam įmonė parduoda: verslui ar vartotojams, kokioms sritims ar klientų tipams.', false, 80],
            ['positioning', 'Positioning', 'text', 'What they sell', 'Kuo įmonė save išskiria — vertės pasiūlymas jos pačios žodžiais. Palik tuščią, jei puslapyje to nepasakyta.', false, 90],
            ['recent_developments', 'Recent developments', 'array', 'Why call now', 'Nauji produktai, plėtra, partnerystės ar kiti pokyčiai, apie kuriuos skelbia pati įmonė.', false, 100],
            ['locations', 'Locations', 'array', 'Scale', 'Filialai, biurai ar padaliniai — miestai arba adresai.', false, 110],
            ['brands', 'Brands', 'array', 'Scale', 'Įmonės valdomi ar atstovaujami prekės ženklai.', false, 120],
        ];

        DB::table('profile_fields')->insert(array_map(
            static fn (array $r): array => [
                'key' => $r[0], 'label' => $r[1], 'type' => $r[2], 'group' => $r[3],
                'extraction_hint' => $r[4], 'required' => $r[5], 'enabled' => true,
                'sort_order' => $r[6], 'created_at' => $now, 'updated_at' => $now,
            ],
            $rows,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_fields');
    }
};
