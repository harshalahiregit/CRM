<?php

use App\Models\Hr\HrExitClearanceItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exit clearance departments, and who may act for each of them.
 *
 * The five departments were a PHP constant and the authorisation was one
 * canManageHrQueue() check covering all of them, so anybody on the HR queue
 * could clear IT, Finance or the reporting manager's item. The only other
 * candidate was hr_exit_clearance_items.assigned_to, which is a free-text
 * name copied from reporting_manager_name — a label, not an identity, and
 * unusable as an authority.
 *
 * SAFE ON DEPLOY. Every tenant is seeded with its five departments and NO
 * authorities, which leaves all five in HR-queue fallback — exactly today's
 * behaviour. A department starts enforcing the moment it is given its first
 * user or role, and only that department.
 *
 * Nothing existing is rewritten. hr_exit_clearance_items.department keeps its
 * string snapshot and gains no foreign key, so a department renamed or retired
 * later cannot disturb a clearance already under way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_clearance_departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // Matches the string written onto the item. Not a key the item
            // points at — the item owns its own copy.
            $table->string('name', 100);

            $table->boolean('is_mandatory')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'hr_clr_dept_tenant_name_uniq');
            $table->index(['tenant_id', 'sort_order'], 'hr_clr_dept_tenant_order_idx');
        });

        Schema::create('hr_clearance_department_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['department_id', 'user_id'], 'hr_clr_dept_user_uniq');
            $table->index(['tenant_id', 'department_id'], 'hr_clr_dept_user_tenant_idx');
        });

        Schema::create('hr_clearance_department_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('staff_role_id');
            $table->timestamps();

            $table->unique(['department_id', 'staff_role_id'], 'hr_clr_dept_role_uniq');
            $table->index(['tenant_id', 'department_id'], 'hr_clr_dept_role_tenant_idx');
        });

        $this->seedExistingTenants();
    }

    /**
     * Give every workspace the five departments it is already using.
     *
     * With no users and no roles attached, so every one of them is in fallback
     * and the HR queue keeps working exactly as it does today. Idempotent per
     * tenant: one that somehow already has rows is left alone rather than
     * having a second set appended.
     */
    private function seedExistingTenants(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        $now = now();

        // Soft-deleted workspaces are skipped. A restored one falls back to the
        // same constant at seeding time, so it is not stranded either.
        $tenants = DB::table('tenants')->whereNull('deleted_at')->pluck('id');

        foreach ($tenants as $tenantId) {
            $already = DB::table('hr_clearance_departments')->where('tenant_id', $tenantId)->exists();

            if ($already) {
                continue;
            }

            $rows = [];
            $order = 0;

            foreach (HrExitClearanceItem::DEPARTMENTS as $name) {
                $rows[] = [
                    'tenant_id'    => $tenantId,
                    'name'         => $name,
                    'is_mandatory' => true,
                    'sort_order'   => $order++,
                    'is_active'    => true,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
            }

            if ($rows) {
                DB::table('hr_clearance_departments')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        // Configuration only. Clearances and their items are never touched by
        // this migration in either direction.
        Schema::dropIfExists('hr_clearance_department_roles');
        Schema::dropIfExists('hr_clearance_department_users');
        Schema::dropIfExists('hr_clearance_departments');
    }
};
