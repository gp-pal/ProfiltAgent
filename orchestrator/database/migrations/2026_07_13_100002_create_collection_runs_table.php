<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per collection run (keyed by run hash) — which target, which sources
 * were touched, the resulting score/verdict. The audit spine of the registry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_hash')->unique();
            $table->string('company_key')->index();
            $table->string('company_code')->nullable();
            $table->string('name');
            $table->string('session')->nullable();
            $table->json('sources_used')->nullable();
            $table->float('overall_score')->nullable();
            $table->string('verdict')->nullable();
            $table->string('status')->default('running'); // running | done | failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_runs');
    }
};
