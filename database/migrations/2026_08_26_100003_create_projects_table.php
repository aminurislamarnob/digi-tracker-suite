<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            /*
             * The hash is the only routing key the protocol gives us, and it
             * decides which account a payload lands in. It must be a UUIDv4 --
             * never sequential, never guessable -- because it travels as a
             * plain body field in GPL-visible source.
             */
            $table->uuid('hash')->unique();

            $table->string('name');
            $table->string('slug');
            $table->string('type')->default('plugin');
            $table->string('homepage_url')->nullable();
            $table->string('demo_url')->nullable();
            $table->text('description')->nullable();
            $table->string('icon_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
