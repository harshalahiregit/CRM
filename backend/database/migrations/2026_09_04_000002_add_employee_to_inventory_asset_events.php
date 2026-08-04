<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which employee an asset event concerns.
 *
 * Returning an asset clears `assigned_employee_id`, so without this the register
 * forgets who ever held it and a "Returned" count could only be fabricated.
 * The movement history stays in Inventory; HRMS reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_asset_events', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_asset_events', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable();
            }
        });

        Schema::table('inventory_asset_events', function (Blueprint $table) {
            $table->index(['tenant_id', 'employee_id'], 'inv_asset_events_tenant_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_asset_events', function (Blueprint $table) {
            $table->dropIndex('inv_asset_events_tenant_employee_idx');
        });
    }
};
