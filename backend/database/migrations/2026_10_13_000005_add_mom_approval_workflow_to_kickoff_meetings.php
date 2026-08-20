<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MOM approval workflow (Meeting.docx — minutes are approved before distribution).
 *
 * Adds a lifecycle to the minutes: Draft → Pending Approval → Approved →
 * Distributed, with who/when stamps for each hand-off and a single note column
 * that carries either the approver's comment or the return-for-revision reason.
 *
 * Distribution is the existing vendor-acknowledgement send — so meetings already
 * sent for acknowledgement are back-filled to Distributed, and their distribution
 * time is taken from acknowledgement_sent_at, keeping their history coherent under
 * the new lifecycle rather than showing them as un-approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->string('mom_status', 32)->default('Draft')->after('minutes')->index();
            $table->timestamp('mom_submitted_at')->nullable()->after('mom_status');
            $table->unsignedBigInteger('mom_submitted_by')->nullable()->after('mom_submitted_at');
            $table->timestamp('mom_approved_at')->nullable()->after('mom_submitted_by');
            $table->unsignedBigInteger('mom_approved_by')->nullable()->after('mom_approved_at');
            $table->text('mom_approval_note')->nullable()->after('mom_approved_by');
            $table->timestamp('mom_distributed_at')->nullable()->after('mom_approval_note');
            $table->unsignedBigInteger('mom_distributed_by')->nullable()->after('mom_distributed_at');
        });

        // Back-fill: anything already sent for acknowledgement is Distributed.
        DB::table('kickoff_meetings')
            ->whereNotNull('acknowledgement_sent_at')
            ->update([
                'mom_status'         => 'Distributed',
                'mom_distributed_at' => DB::raw('acknowledgement_sent_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->dropColumn([
                'mom_status', 'mom_submitted_at', 'mom_submitted_by',
                'mom_approved_at', 'mom_approved_by', 'mom_approval_note',
                'mom_distributed_at', 'mom_distributed_by',
            ]);
        });
    }
};
