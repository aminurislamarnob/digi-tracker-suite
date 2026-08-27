<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The whitelist for the SDK's extra[] bag.
 *
 * add_extra() lets a plugin author send anything they like, which means
 * without a whitelist the payload is an unbounded, untyped funnel into
 * our database -- and the one place a careless integration could push
 * personal data we never asked for. Unregistered keys are dropped at
 * ingest; registered ones are cast to their declared datatype so a chart
 * is not summing the string "12".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_meta_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('key', 64);
            $table->string('datatype', 16)->default('string');
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_meta_fields');
    }
};
