<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The download figures wordpress.org publishes as a set.
 *
 * `downloaded` was already here, filled from plugin_information -- which
 * turned out to omit the field unless it is asked for by name, so every
 * snapshot so far recorded a null that displayed as zero.
 *
 * These three come from the same call that fixes it: the summary endpoint
 * the plugin directory's own page uses. Storing them costs three small
 * columns and buys a fallback, so the dashboard still has yesterday's
 * numbers on a day wordpress.org cannot be reached, rather than four
 * dashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repo_snapshots', function (Blueprint $table) {
            /*
             * Nullable, and null means "not captured" rather than "no
             * downloads". Every snapshot taken before this migration has
             * no summary at all, and reading those as zeros would put a
             * cliff in the history on the day this shipped.
             */
            $table->unsignedInteger('downloads_today')->nullable()->after('downloaded');
            $table->unsignedInteger('downloads_yesterday')->nullable()->after('downloads_today');
            $table->unsignedInteger('downloads_last_week')->nullable()->after('downloads_yesterday');
        });
    }

    public function down(): void
    {
        Schema::table('repo_snapshots', function (Blueprint $table) {
            $table->dropColumn(['downloads_today', 'downloads_yesterday', 'downloads_last_week']);
        });
    }
};
