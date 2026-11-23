<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-action log within a run: each source touched, what it yielded, per-field
 * scores, and status. The "registry of all actions per company" detail level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('run_hash')->index();
            $table->string('source');   // registry_db | official_site | open_web | ...
            $table->string('action');   // fetch | search | extract | judge | ...
            $table->string('status')->default('ok'); // ok | skipped | error
            $table->json('yielded')->nullable();
            $table->json('field_scores')->nullable();
            $table->text('detail')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_actions');
    }
};
