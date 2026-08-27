<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which wordpress.org listing a project corresponds to.
 *
 * Kept separate from `slug` because the two answer different questions.
 * `slug` is ours: unique per account, used in dashboard URLs, and free to
 * change. This one is wordpress.org's, is not ours to change, and is the
 * key every public endpoint is addressed by.
 *
 * Nullable on purpose. A project that is not on the repository -- a private
 * plugin, or one not published yet -- still collects telemetry perfectly
 * well; it simply has no public half to compare against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('wporg_slug')->nullable()->after('slug');

            // Not unique: two accounts watching the same public listing is
            // legitimate, and enforcing otherwise would let one account
            // discover what another is tracking.
            $table->index('wporg_slug');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['wporg_slug']);
            $table->dropColumn('wporg_slug');
        });
    }
};
