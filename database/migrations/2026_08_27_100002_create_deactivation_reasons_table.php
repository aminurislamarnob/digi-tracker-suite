<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reason list a project offers on deactivation.
 *
 * Seeded with the SDK's seven so a stock integration works untouched, but
 * per project, because the useful question differs by plugin and the
 * dashboard needs a label for any reason_id that arrives -- including one
 * an author added themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deactivation_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('reason_id', 64);
            $table->string('label');
            $table->string('placeholder')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'reason_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deactivation_reasons');
    }
};
