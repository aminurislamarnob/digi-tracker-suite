<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a project whose telemetry was invented rather than received.
 *
 * A dashboard is a tool for making decisions -- "can we drop PHP 7.4?",
 * "is this release landing?" -- and a plausible-looking number nobody
 * measured is worse than no number at all. Demo projects therefore carry
 * a flag that the panel renders as a banner nobody can dismiss, and the
 * ingest path refuses to write real heartbeats into them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
