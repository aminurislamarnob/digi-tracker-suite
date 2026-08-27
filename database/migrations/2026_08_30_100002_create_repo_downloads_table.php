<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily download counts from wordpress.org.
 *
 * Separate from repo_snapshots because it is a different kind of data.
 * A snapshot is "what was true today"; this is a history the repository
 * hands over in bulk -- hundreds of days in one request -- so the first
 * fetch backfills years and every later fetch corrects the tail.
 *
 * Rows are upserted rather than inserted: wordpress.org revises recent
 * days as mirrors report in, so today's number is provisional for a while.
 * Treating it as final is how a dashboard ends up showing a cliff every
 * time somebody looks at it before the count settles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repo_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->unsignedInteger('downloads')->default(0);

            $table->timestamps();

            $table->unique(['project_id', 'date']);
            $table->index(['project_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_downloads');
    }
};
