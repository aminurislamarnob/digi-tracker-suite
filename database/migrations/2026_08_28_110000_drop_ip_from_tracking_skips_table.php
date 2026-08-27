<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stop recording the IP address of people who declined.
 *
 * `/tracking-skipped` is the one route that fires without consent, and that
 * is defensible: it carries a project hash and a boolean, nothing that
 * identifies anyone, and without it the opt-in rate is unmeasurable.
 *
 * Recording the requesting IP alongside it was not defensible. An IP is
 * personal data, and taking it from somebody in the act of refusing is
 * exactly the thing the refusal was about. The column is dropped rather
 * than left nullable: a column that exists eventually gets filled.
 *
 * Anomaly detection loses nothing -- it reads raw_payloads, and a refusal
 * is not the traffic it watches for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_skips', function (Blueprint $table) {
            $table->dropColumn('ip');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_skips', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('previously_skipped');
        });
    }
};
