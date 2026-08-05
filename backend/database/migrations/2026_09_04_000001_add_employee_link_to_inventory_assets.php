<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the Inventory asset register point at an HR employee.
 *
 * `assigned_to` is a users.id, and no employee on this instance has a user
 * account — so without this column an asset can never be tied to an employee.
 * The column lives on the Inventory table on purpose: Inventory stays the single
 * asset register, and HRMS only ever reads through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_assets', 'assigned_employee_id')) {
                $table->unsignedBigInteger('assigned_employee_id')->nullable();
            }
            // Physical condition of the unit — an asset attribute, not an HR one.
            if (! Schema::hasColumn('inventory_assets', 'condition')) {
                $table->string('condition', 40)->nullable();
            }
        });

        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->index(['tenant_id', 'assigned_employee_id'], 'inv_assets_tenant_employee_idx');
        });
    }

    /** Index-only: SQLite cannot drop a column that an index still covers. */
    public function down(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->dropIndex('inv_assets_tenant_employee_idx');
        });
    }
};
