<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the approval settings real.
 *
 * Settings > Approval already offered require_approval_receipt / _delivery /
 * _adjustment and a threshold amount. Nothing read them — the toggles saved
 * happily and changed nothing, which is worse than not having them: a manager
 * switches on "delivery needs approval", believes stock is now gated, and it
 * isn't.
 *
 * The document flow becomes:
 *     draft -> pending_approval -> approved -> posted
 * with rejection bouncing back to draft carrying a reason, so the requester
 * knows what to fix. When no rule applies, draft posts directly exactly as
 * before — the gate only exists where a tenant asked for one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('approved_by')->nullable()->after('posted_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('approved_at');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->timestamp('submitted_at')->nullable()->after('rejection_reason');

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_vouchers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropColumn([
                'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
                'rejection_reason', 'submitted_at',
            ]);
        });
    }
};
