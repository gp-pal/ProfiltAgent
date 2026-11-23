<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accumulating "best-known" profile per target company — the queryable
 * system-of-record the universal collector builds up across runs and sources.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('company_key')->unique();      // code:<code> or name:<slug>
            $table->string('company_code')->nullable()->index();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->json('fields')->nullable();           // merged profile fields
            $table->json('field_coverage')->nullable();   // per-field score 0..1
            $table->json('field_sources')->nullable();    // per-field provenance
            $table->float('overall_score')->default(0);
            $table->string('verdict')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profiles');
    }
};
