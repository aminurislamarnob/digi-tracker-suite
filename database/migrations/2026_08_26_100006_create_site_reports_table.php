<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only heartbeat log. One row per /track received.
 *
 * `sites` holds what is true now so list views stay fast; this holds the
 * history so any chart can be rebuilt. Nothing here is ever updated.
 *
 * Dashboards must never aggregate this table directly -- MySQL JSON
 * aggregation is slow enough that every chart reads daily_stats instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('project_version', 32)->nullable();

            $table->string('wp_version', 32)->nullable();
            $table->string('php_version', 32)->nullable();
            $table->string('mysql_version', 32)->nullable();
            $table->string('server_software')->nullable();
            $table->string('locale', 32)->nullable();
            $table->boolean('multisite')->default(false);
            $table->string('memory_limit', 32)->nullable();
            $table->boolean('debug_mode')->default(false);

            $table->string('theme_slug')->nullable();
            $table->string('theme_name')->nullable();
            $table->string('theme_version', 32)->nullable();

            $table->unsignedInteger('users_total')->nullable();
            $table->json('users_by_role')->nullable();
            $table->unsignedInteger('active_plugins')->nullable();
            $table->unsignedInteger('inactive_plugins')->nullable();

            $table->json('extra')->nullable();

            $table->string('client_version', 32)->nullable();
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index(['project_id', 'reported_at']);
            $table->index(['site_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_reports');
    }
};
