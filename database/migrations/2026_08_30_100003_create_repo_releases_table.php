<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each version actually shipped.
 *
 * This is the table the plan said could not exist. Version adoption was
 * built without days-to-50% because "it needs a release date per version,
 * which nothing records" -- true of telemetry, which knows what sites run
 * but not when a version became available.
 *
 * The repository does record it. Every plugin's Subversion tags directory
 * carries a creation date per tag, and one request returns the whole
 * history, so releases going back years are recoverable rather than only
 * those observed from today onward.
 *
 * `source` keeps the two provenances apart. A date read from Subversion is
 * when the tag was cut; a date inferred from watching the version field
 * change is only ever "no later than this", and the difference matters as
 * soon as anyone measures adoption speed from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repo_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('version');
            $table->date('released_on');

            // 'svn'      -- the tag's own creation date, authoritative.
            // 'observed' -- the day we noticed the version field change,
            //               which is an upper bound and nothing more.
            $table->string('source')->default('svn');

            $table->timestamps();

            $table->unique(['project_id', 'version']);
            $table->index(['project_id', 'released_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_releases');
    }
};
