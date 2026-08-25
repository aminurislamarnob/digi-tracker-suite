<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('end_user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The protocol has no site identifier, so we synthesise one from the
             * canonicalised home URL. Without canonicalisation https://x.com and
             * http://www.x.com/ are two installs and every count is wrong.
             */
            $table->string('site_key', 40);

            $table->string('url');
            $table->string('canonical_url');
            $table->string('ua_fingerprint', 32)->nullable();
            $table->string('name')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('country', 2)->nullable();
            $table->boolean('is_local')->default(false);

            $table->string('current_version', 32)->nullable();
            $table->string('wp_version', 32)->nullable();
            $table->string('php_version', 32)->nullable();

            $table->string('status')->default('active');

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'site_key']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
