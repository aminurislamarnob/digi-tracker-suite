<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The most sensitive thing this platform collects.
 *
 * A per-site plugin list is a fingerprint of somebody's business and, read
 * the wrong way, an inventory of their attack surface. It earns its place
 * only as an aggregate -- "what runs alongside us" -- so by policy it is
 * never browsable per site and never leaves via CSV export.
 *
 * Current state only, deliberately: no history table, so a deleted site
 * takes its inventory with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('slug', 191);
            $table->string('name')->nullable();
            $table->string('version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'slug']);

            // Drives the only view this table has: most-used plugins alongside ours.
            $table->index(['project_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_plugins');
    }
};
