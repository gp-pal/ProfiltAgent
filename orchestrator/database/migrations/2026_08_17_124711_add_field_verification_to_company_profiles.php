<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-field match VERIFICATION, the sibling of the per-field provenance already in
 * `field_sources`: provenance says which source a value came from, this says what was
 * established about that source's claim to be the target company, and when.
 *
 * Stored rather than re-derived per run so a value contributed by a genuinely verified
 * source still reads as verified on every later run — the thing a per-run inference can
 * only approximate.
 *
 * NULL is deliberate and load-bearing: every row that exists when this migration runs
 * carries no verification information, and "no record" must never be readable as
 * "verified". There is therefore NO data backfill here — an absent entry means
 * {@see \App\Models\CompanyProfile::VERIFICATION_UNKNOWN}, which
 * {@see \App\Models\CompanyProfile::verificationOf()} is the single place to interpret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table): void {
            // {field: {status, source, verdict, basis, at}} — see CompanyProfile::verificationOf()
            $table->json('field_verification')->nullable()->after('field_sources');
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table): void {
            $table->dropColumn('field_verification');
        });
    }
};
