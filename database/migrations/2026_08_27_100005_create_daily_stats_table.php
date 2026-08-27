<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly rollup every chart reads.
 *
 * This table exists because we chose MySQL, where aggregating JSON across
 * a quarter of a million site_reports rows is slow enough to make a
 * dashboard feel broken. The rule it enforces is absolute: charts read
 * this table and never site_reports.
 *
 * The by_* columns are JSON maps of value to count -- {"8.2.4": 91} --
 * because the distributions are open-ended and a column per PHP version
 * is not a schema, it is a migration treadmill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->date('date');

            $table->unsignedInteger('active_installs')->default(0);
            $table->unsignedInteger('new_installs')->default(0);
            $table->unsignedInteger('deactivations')->default(0);
            $table->unsignedInteger('reactivations')->default(0);
            $table->unsignedInteger('opted_in')->default(0);
            $table->unsignedInteger('skipped')->default(0);

            $table->json('by_version')->nullable();
            $table->json('by_php')->nullable();
            $table->json('by_wp')->nullable();
            $table->json('by_mysql')->nullable();
            $table->json('by_locale')->nullable();
            $table->json('by_server')->nullable();
            $table->json('by_theme')->nullable();
            $table->json('by_multisite')->nullable();
            $table->json('by_country')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
