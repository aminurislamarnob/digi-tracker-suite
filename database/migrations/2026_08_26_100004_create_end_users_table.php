<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people who run the sites our customers' plugins are installed on.
 *
 * Named end_users to keep `users` free for platform logins. One person can
 * own many sites, which is the whole reason this is a separate table.
 *
 * The email is encrypted at rest, so it cannot be queried directly. The
 * blind index -- a keyed hash of the normalised address -- is what makes
 * exact-match lookup possible without storing anything searchable in clear.
 * Exact match is the only search we offer, deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('end_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->text('email')->nullable();
            $table->string('email_index', 64)->nullable();
            $table->text('first_name')->nullable();
            $table->text('last_name')->nullable();

            /*
             * Telemetry consent is not marketing consent. Appsero's Mailchimp
             * integration adds a contact the moment a user allows telemetry;
             * GDPR wants those two consents separate and specific, so we record
             * marketing separately and it stays null until explicitly given.
             */
            $table->timestamp('marketing_consent_at')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'email_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('end_users');
    }
};
