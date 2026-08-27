<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per message this platform sent, and what became of it.
 *
 * Exists to answer two questions that are otherwise unanswerable: "did we
 * actually mail this person?" and "how many times have we mailed them?"
 * Both matter when the recipient is somebody who opted into telemetry, not
 * into correspondence.
 *
 * `end_user_id` is nullable because not every message goes to an end user
 * -- the forward and the digest go to the author's own inbox, and those
 * still need a record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('end_user_id')->nullable()->constrained()->nullOnDelete();

            // deactivation_reply | deactivation_forward | weekly_digest
            $table->string('type', 40);

            /*
             * The recipient as a blind index, never in the clear. Enough to
             * answer "have we written to this person" without the log itself
             * becoming a mailing list.
             */
            $table->string('recipient_index', 64)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'type', 'sent_at']);
            $table->index(['account_id', 'recipient_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_events');
    }
};
