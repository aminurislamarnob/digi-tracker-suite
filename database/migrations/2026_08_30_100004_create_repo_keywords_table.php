<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search terms a project wants its repository position tracked for.
 *
 * Chosen by hand rather than guessed. A generated keyword list produces
 * hundreds of terms nobody searches, and each one costs a request against
 * a public API we are a guest on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repo_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('keyword');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Normalised to lowercase before writing, so "Email Sender" and
            // "email sender" cannot both be tracked as separate terms and
            // then disagree with each other on the same chart.
            $table->unique(['project_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_keywords');
    }
};
