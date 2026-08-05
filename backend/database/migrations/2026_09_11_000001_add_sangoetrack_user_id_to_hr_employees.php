<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a CRM employee to its SangoeTrack (track.sangoe.in) HRM user.
 *
 * Nullable on purpose: an employee with no SangoeTrack account is simply never
 * synced, and existing rows stay valid. The unique index is on
 * (tenant_id, sangoetrack_user_id) rather than the column alone — SangoeTrack
 * user ids are only unique inside their own workspace, so two tenants may
 * legitimately map to the same remote id. MySQL treats NULLs as distinct in a
 * unique index, so unmapped employees do not collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->unsignedBigInteger('sangoetrack_user_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('sangoetrack_workspace_id')->nullable()->after('sangoetrack_user_id');
            $table->timestamp('sangoetrack_synced_at')->nullable()->after('sangoetrack_workspace_id');

            $table->unique(['tenant_id', 'sangoetrack_user_id'], 'hr_employees_tenant_sangoetrack_unique');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropUnique('hr_employees_tenant_sangoetrack_unique');
            $table->dropColumn(['sangoetrack_user_id', 'sangoetrack_workspace_id', 'sangoetrack_synced_at']);
        });
    }
};
