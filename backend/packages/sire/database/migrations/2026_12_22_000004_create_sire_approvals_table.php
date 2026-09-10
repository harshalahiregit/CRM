<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — the approval register.
 *
 * Copies the purchase_approval_requests / tpv_approvals SHAPE verbatim so that a
 * future consolidation of the CRM's five approval implementations is a merge and
 * not a rewrite. SIRE does not build a sixth engine (D6).
 *
 * Used by the change-request track: `request_change_approval` opens a pending row,
 * `approve_change` and `reject_change` decide it. Without this table the change
 * track would transition with no record of who approved what — which is the one
 * thing an approval workflow exists to prevent.
 *
 * No soft deletes: an approval register is append-only. Cancel, never delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_approvals')) {
            return;
        }

        Schema::create('sire_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // change_request | report_closure | action_verification
            $table->string('approval_type', 48);

            // SIRE-internal polymorphism: sire_report | sire_action
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');

            $table->unsignedTinyInteger('level')->default(1);   // multi-level is schema-ready, unused
            $table->string('status', 16)->default('pending');   // pending | approved | rejected | cancelled

            $table->unsignedBigInteger('requested_by');
            $table->dateTime('requested_at');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('remarks')->nullable();

            $table->dateTime('due_at')->nullable();
            $table->dateTime('escalated_at')->nullable();       // scheduler dedupe

            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'status'], 'sire_apr_tenant_status_idx');
            $table->index(['tenant_id', 'approval_type'], 'sire_apr_tenant_type_idx');
            $table->index(['subject_type', 'subject_id'], 'sire_apr_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_approvals');
    }
};
