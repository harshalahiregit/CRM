<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase MOM approval lifecycle (Sangoe TPV §9 parity) — mirrors the shared
 * kickoff engine's two-level MOM approval onto purchase_kickoff_meetings. The
 * minutes now move Draft → Pending Organizer → Pending Chairperson → Approved →
 * Distributed, and only an Approved MOM may be published for vendor
 * acknowledgement.
 *
 * Back-fill: any meeting whose minutes were already published (ack_token minted
 * or acknowledged) is stamped Distributed so it isn't retro-blocked by the new
 * approval gate; everything else stays Draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_kickoff_meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_kickoff_meetings', 'mom_status')) {
                $table->string('mom_status', 32)->default('Draft')->after('minutes')->index();
            }
            if (! Schema::hasColumn('purchase_kickoff_meetings', 'mom_submitted_at')) {
                $table->timestamp('mom_submitted_at')->nullable()->after('mom_status');
                $table->unsignedBigInteger('mom_submitted_by')->nullable()->after('mom_submitted_at');
                $table->timestamp('mom_organizer_approved_at')->nullable()->after('mom_submitted_by');
                $table->unsignedBigInteger('mom_organizer_approved_by')->nullable()->after('mom_organizer_approved_at');
                $table->timestamp('mom_approved_at')->nullable()->after('mom_organizer_approved_by');
                $table->unsignedBigInteger('mom_approved_by')->nullable()->after('mom_approved_at');
                $table->text('mom_approval_note')->nullable()->after('mom_approved_by');
                $table->timestamp('mom_distributed_at')->nullable()->after('mom_approval_note');
                $table->unsignedBigInteger('mom_distributed_by')->nullable()->after('mom_distributed_at');
                $table->timestamp('mom_viewed_at')->nullable()->after('mom_distributed_by');
            }
        });

        // Meetings already sent for acknowledgement pre-date the approval gate —
        // treat them as Distributed so they remain valid.
        DB::table('purchase_kickoff_meetings')
            ->where(function ($q) {
                $q->whereNotNull('ack_token')->orWhereNotNull('acknowledged_at');
            })
            ->update(['mom_status' => 'Distributed']);
    }

    public function down(): void
    {
        Schema::table('purchase_kickoff_meetings', function (Blueprint $table) {
            foreach ([
                'mom_submitted_at', 'mom_submitted_by',
                'mom_organizer_approved_at', 'mom_organizer_approved_by',
                'mom_approved_at', 'mom_approved_by', 'mom_approval_note',
                'mom_distributed_at', 'mom_distributed_by', 'mom_viewed_at',
            ] as $col) {
                if (Schema::hasColumn('purchase_kickoff_meetings', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('purchase_kickoff_meetings', 'mom_status')) {
                $table->dropIndex(['mom_status']);
                $table->dropColumn('mom_status');
            }
        });
    }
};
