<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only churn log. Never updated, never deduplicated.
 *
 * A site that deactivates, reactivates and deactivates again has churned
 * twice, and both events matter -- collapsing them into a status flag on
 * `sites` would destroy the only feedback this platform ever receives in
 * the user's own words.
 *
 * The theme columns are copied in rather than joined: "which themes do we
 * churn from most" is a question about the site as it was at that moment,
 * and the site row moves on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deactivations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('reason_id', 64)->nullable();
            $table->text('reason_info')->nullable();

            $table->string('project_version', 32)->nullable();
            $table->string('theme_slug')->nullable();
            $table->string('theme_name')->nullable();
            $table->string('theme_version', 32)->nullable();

            /*
             * Stamped when a heartbeat later arrives from the same site.
             *
             * Reactivation lives here rather than as a flag on `sites`
             * because `sites` only ever holds the latest truth, and a
             * nightly rollup has to be able to answer "how many came back
             * on the 3rd?" long after the 3rd. It also makes time-to-return
             * a subtraction rather than a reconstruction.
             */
            $table->timestamp('reactivated_at')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['project_id', 'reactivated_at']);
            $table->index(['project_id', 'reason_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deactivations');
    }
};
