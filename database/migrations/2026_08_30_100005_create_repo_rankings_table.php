<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a project sat in repository search for one keyword, on one day.
 *
 * `position` is nullable and that is the interesting case: null means the
 * plugin was not found within the pages we looked at, which is different
 * from "ranked last" and must not average in as a number. Storing 0 or 999
 * for it would quietly drag every mean toward a value that never happened.
 *
 * `searched_depth` records how far we actually looked, so a null can be
 * read honestly later -- "not in the top 100" is a much weaker statement
 * than "not in the top 500", and without this column the two are
 * indistinguishable after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repo_rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repo_keyword_id')->constrained()->cascadeOnDelete();

            $table->date('captured_on');

            $table->unsignedSmallInteger('position')->nullable();
            $table->unsignedSmallInteger('searched_depth');
            $table->unsignedInteger('total_results')->nullable();

            $table->timestamps();

            $table->unique(['repo_keyword_id', 'captured_on']);
            $table->index(['project_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_rankings');
    }
};
