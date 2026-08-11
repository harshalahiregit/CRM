<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A kickoff meeting may cover more than one third-party vendor.
 *
 * `kickoff_meetings.kickoffable_*` stays as-is and keeps holding the FIRST
 * vendor. That is deliberate: the vendor portal ("my meetings"), the onboarding
 * pointer, the repository's subject filter and TpvOnboarding::kickoffMeetings()
 * all query those two columns, and moving every meeting into a pivot would mean
 * rewriting each of them at once. Instead the primary stays where it is and this
 * table carries the full set — including the primary, so one query answers "who
 * is on this meeting" without a union.
 *
 * Acknowledgement is PER VENDOR. The meeting's own ack_token/acknowledged_at
 * describe the primary vendor and are left untouched; each additional vendor
 * gets its own token here. A meeting is fully acknowledged when every row is.
 * One shared token could not tell you which vendor had actually signed off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kickoff_meeting_subjects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('kickoff_meeting_id')->index();

            // Polymorphic for the same reason the meeting itself is: a kickoff can
            // be held with a TPV vendor or a purchase vendor.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            // The one mirrored from kickoffable_*. Exactly one row per meeting
            // carries this, and it is the vendor every legacy query still sees.
            $table->boolean('is_primary')->default(false);

            // Per-vendor acknowledgement, mirroring the meeting's own columns.
            $table->string('ack_token', 64)->nullable()->unique();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_by_name')->nullable();
            $table->string('acknowledged_ip')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'kickoff_meeting_id']);
            $table->index(['subject_type', 'subject_id']);
            // A vendor cannot be on the same meeting twice.
            $table->unique(['kickoff_meeting_id', 'subject_type', 'subject_id'], 'kms_meeting_subject_unique');
        });

        // Backfill: every existing meeting becomes a one-vendor meeting, so the
        // pivot is authoritative from day one and no code needs a "pivot empty?"
        // special case.
        if (Schema::hasTable('kickoff_meetings')) {
            DB::table('kickoff_meetings')
                ->whereNotNull('kickoffable_type')
                ->whereNotNull('kickoffable_id')
                ->orderBy('id')
                ->chunkById(200, function ($meetings) {
                    $rows = [];
                    foreach ($meetings as $m) {
                        $rows[] = [
                            'tenant_id'            => $m->tenant_id,
                            'kickoff_meeting_id'   => $m->id,
                            'subject_type'         => $m->kickoffable_type,
                            'subject_id'           => $m->kickoffable_id,
                            'is_primary'           => true,
                            // Carry the meeting's existing acknowledgement across so
                            // an already-signed meeting does not read as unsigned.
                            'ack_token'            => $m->ack_token ?: Str::random(48),
                            'acknowledged_at'      => $m->acknowledged_at,
                            'acknowledged_by_name' => $m->acknowledged_by_name,
                            'acknowledged_ip'      => $m->acknowledged_ip,
                            'created_at'           => now(),
                            'updated_at'           => now(),
                        ];
                    }
                    if ($rows) {
                        DB::table('kickoff_meeting_subjects')->insert($rows);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kickoff_meeting_subjects');
    }
};
