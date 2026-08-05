<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three additive groups on kickoff_meetings. Every column is nullable and
 * nothing existing is altered or dropped.
 *
 * 1. Split location. `location` stays as-is and remains what the PDF, the
 *    reminder email and the portal read, so an old meeting still renders. The
 *    new city/venue/address are the structured form; the service composes
 *    `location` from them when they are supplied, which keeps one displayable
 *    string for every consumer that already expects one.
 *
 * 2. planned_date — the date the kickoff was ORIGINALLY promised for, as a
 *    plain date. Distinct from scheduled_at (the actual date+time now booked)
 *    and from original_scheduled_at, which only gets written when a meeting is
 *    moved to Delayed and therefore says nothing about a meeting that was
 *    always on time.
 *
 * 3. Acknowledgement window. ack_token / acknowledged_at are untouched and the
 *    existing token flow keeps working; these record when the MOM was sent, when
 *    the window shuts, and the resulting state so an expired acknowledgement is
 *    distinguishable from one that was never sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->string('city')->nullable()->after('location');
            $table->string('venue')->nullable()->after('city');
            $table->string('address')->nullable()->after('venue');

            $table->date('planned_date')->nullable()->after('scheduled_at');

            $table->timestamp('acknowledgement_sent_at')->nullable()->after('acknowledged_ip');
            $table->timestamp('acknowledgement_deadline')->nullable()->after('acknowledgement_sent_at');
            // pending | acknowledged | expired ; null = never sent for acknowledgement
            $table->string('acknowledgement_status', 20)->nullable()->after('acknowledgement_deadline');

            $table->index(['tenant_id', 'planned_date']);
        });
    }

    public function down(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'planned_date']);
            $table->dropColumn([
                'city', 'venue', 'address', 'planned_date',
                'acknowledgement_sent_at', 'acknowledgement_deadline', 'acknowledgement_status',
            ]);
        });
    }
};
