<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase ← TPV parity: the permit-to-work lifecycle.
 *
 * purchase_work_permits could hold a permit — type, hazards, precautions, a
 * validity window — but had nowhere to record who requested it, who approved or
 * rejected it, on what grounds, or when it was closed. A permit to work IS its
 * approval trail; without those columns the table stores an intention, not a
 * permit, and "who let this work happen?" has no answer.
 *
 * Mirrors work_permits (TPV's shared table) exactly, minus vendor_id, which
 * Purchase already carries as purchase_vendor_id.
 *
 * The JSA steps table is created alongside: a permit's hazard analysis is a
 * numbered list of activity/hazard/control rows, and folding it into the
 * permit's free-text `hazards` column loses the per-step residual risk that
 * makes a JSA reviewable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_work_permits', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->after('purchase_vendor_id');
            $table->unsignedBigInteger('approved_by')->nullable()->after('status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            // Why it was approved OR rejected. One column for both: a rejection
            // without a reason cannot be answered, and splitting them invites
            // recording only the happy path.
            $table->string('decision_remarks', 1000)->nullable()->after('approved_at');
            $table->timestamp('closed_at')->nullable()->after('decision_remarks');
            $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_permit_jsa_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('permit_id')->index();
            $table->unsignedSmallInteger('step_no')->default(1);
            $table->string('activity', 500);
            $table->string('hazard', 500)->nullable();
            $table->string('control', 500)->nullable();
            // Kept per step rather than once per permit: the point of a JSA is
            // that one step can stay high-risk after controls while the rest
            // are low, and a single permit-level figure hides exactly that.
            $table->string('residual_risk', 30)->nullable();
            $table->timestamps();

            $table->index(['permit_id', 'step_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_permit_jsa_steps');

        Schema::table('purchase_work_permits', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropColumn([
                'requested_by', 'approved_by', 'approved_at',
                'decision_remarks', 'closed_at', 'closed_by',
            ]);
        });
    }
};
