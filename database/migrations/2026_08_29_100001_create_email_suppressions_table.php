<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses this platform must not write to again.
 *
 * Keyed by blind index rather than the address itself. A suppression list
 * in the clear is a list of real email addresses sitting in a table whose
 * whole purpose is that we stopped talking to those people -- the one
 * table where storing plaintext would be least defensible.
 *
 * Scoped per account, not per project: somebody who unsubscribes from one
 * plugin's mail has not asked to hear from a different plugin they never
 * heard of, and the account is the entity they were dealing with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->string('email_index', 64);

            // unsubscribed | bounced | complained | manual
            $table->string('reason', 32)->default('unsubscribed');

            $table->timestamps();

            $table->unique(['account_id', 'email_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
