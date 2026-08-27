<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public half of the picture: what wordpress.org says about a plugin,
 * captured once a day.
 *
 * This is the counterpart to daily_stats. That table records what opted-in
 * sites told us; this one records what the repository shows the world. The
 * pair is what finally makes the opt-in rate measurable -- our tracked
 * installs over the repository's active_installs -- which until now was a
 * number the dashboard could only gesture at.
 *
 * A snapshot per day, never overwritten in place, because the whole point
 * is the shape over time. wordpress.org publishes no history for most of
 * these fields, so a day not captured is a day gone for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repo_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->date('captured_on');

            /*
             * Reported in rounded buckets -- 500, 10000, 100000 -- never an
             * exact figure. Treat a change as "crossed a threshold", not as
             * a measurement, and never subtract two of them and call the
             * result growth.
             */
            $table->unsignedInteger('active_installs')->nullable();
            $table->unsignedBigInteger('downloaded')->nullable();

            // 0-100, as the API expresses it. num_ratings is the count the
            // percentage is drawn from, and a rating without it is noise:
            // 100% of two people is not a quality signal.
            $table->unsignedTinyInteger('rating')->nullable();
            $table->unsignedInteger('num_ratings')->nullable();
            $table->json('ratings')->nullable();

            $table->unsignedInteger('support_threads')->nullable();
            $table->unsignedInteger('support_threads_resolved')->nullable();

            $table->string('version')->nullable();
            $table->string('requires')->nullable();
            $table->string('requires_php')->nullable();
            $table->string('tested')->nullable();

            $table->timestamp('last_updated_at')->nullable();

            /*
             * wordpress.org's own version split, as percentages of installs.
             * Ours in daily_stats.by_version is counts of opted-in sites, so
             * the two are never directly comparable -- but a wide gap between
             * them says the opted-in population is not representative, which
             * is worth knowing before quoting any of our other numbers.
             */
            $table->json('version_distribution')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'captured_on']);
            $table->index(['project_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_snapshots');
    }
};
