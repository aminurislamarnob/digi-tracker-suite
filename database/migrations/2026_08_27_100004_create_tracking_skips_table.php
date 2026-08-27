<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody declined the opt-in dialog.
 *
 * This is the denominator for the opt-in rate, and it is the only table
 * here with no site: a refusal deliberately carries no URL, no email and
 * no environment -- just the hash and whether they had refused before.
 * That absence is the point, so nothing that identifies a site may be
 * added to it later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->boolean('previously_skipped')->default(false);
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_skips');
    }
};
