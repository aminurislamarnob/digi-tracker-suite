<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project email identity and switches.
 *
 * Mail leaves from our authenticated domain, because a platform cannot
 * inherit each customer's DKIM. What is configurable is what the recipient
 * sees: the author's name in From and their own address in Reply-To, so a
 * reply reaches the person who wrote the plugin rather than us.
 *
 * Every send is off by default. An email nobody deliberately switched on
 * is an email nobody meant to send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('from_name')->nullable()->after('description');
            $table->string('reply_to')->nullable()->after('from_name');
            $table->string('support_email')->nullable()->after('reply_to');
            $table->text('email_footer')->nullable()->after('support_email');

            // Reply to the person who left feedback.
            $table->boolean('replies_to_deactivations')->default(false)->after('email_footer');

            // Copy that feedback to the project's own inbox.
            $table->boolean('forwards_deactivations')->default(false)->after('replies_to_deactivations');

            // Weekly summary to the account's own team.
            $table->boolean('sends_weekly_digest')->default(false)->after('forwards_deactivations');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'from_name', 'reply_to', 'support_email', 'email_footer',
                'replies_to_deactivations', 'forwards_deactivations', 'sends_weekly_digest',
            ]);
        });
    }
};
