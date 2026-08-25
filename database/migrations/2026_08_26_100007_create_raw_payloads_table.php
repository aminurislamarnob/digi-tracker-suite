<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything as it arrived, before interpretation.
 *
 * The heartbeat is weekly and the SDK never retries, so a payload we fail
 * to parse is gone forever unless it was written down first. This is the
 * replay buffer that makes a parser bug recoverable instead of a silent
 * hole in the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('route', 32);
            $table->json('payload');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_payloads');
    }
};
