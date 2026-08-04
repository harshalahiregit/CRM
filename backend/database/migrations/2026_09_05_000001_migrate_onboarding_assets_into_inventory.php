<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move the onboarding asset list into the Inventory register, then retire it.
 *
 * `hr_employee_onboarding_assets` was HRMS's own record of hardware handed to a
 * new joiner. Inventory is the asset register now, so each row becomes a real
 * asset with an assignment history entry.
 *
 * The old table never recorded WHO issued the asset, so `performed_by` is left
 * null rather than invented; the event description says where the row came from.
 * Anything that cannot be resolved to an employee aborts the migration — a row
 * is not dropped quietly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_employee_onboarding_assets')) {
            return;
        }

        $rows = DB::table('hr_employee_onboarding_assets')->orderBy('id')->get();

        foreach ($rows as $row) {
            $onboarding = DB::table('hr_employee_onboardings')->where('id', $row->onboarding_id)->first();
            $employeeId = $onboarding->employee_id ?? null;

            if (! $employeeId || ! DB::table('hr_employees')->where('id', $employeeId)->exists()) {
                throw new RuntimeException(
                    "Onboarding asset #{$row->id} ({$row->asset_type}) has no employee to attach to. "
                    .'Resolve it before running this migration — it will not be discarded.'
                );
            }

            $tenantId = (int) ($row->tenant_id ?: $onboarding->tenant_id);
            $status   = strtolower(trim((string) $row->status));
            $returned = ! empty($row->returned_date) || $status === 'returned';

            // The date it was handed over; fall back to when the row was written.
            $assignedAt = $row->issued_date ?: $row->created_at;

            $assetId = DB::table('inventory_assets')->insertGetId([
                'tenant_id'            => $tenantId,
                'code'                 => 'HR-ONB-'.$row->id,
                'name'                 => $row->asset_type,
                'category'             => $row->asset_type,
                'serial_no'            => $row->asset_tag_serial ?: null,
                'condition'            => $row->condition ?: null,
                // A returned unit is back on the shelf and holds no employee.
                'status'               => $returned ? 'idle' : 'in_service',
                'assigned_employee_id' => $returned ? null : $employeeId,
                'purchase_date'        => null,
                'note'                 => $row->remarks ?: null,
                'created_at'           => $row->created_at,
                'updated_at'           => $row->updated_at,
            ]);

            DB::table('inventory_asset_events')->insert([
                'tenant_id'    => $tenantId,
                'asset_id'     => $assetId,
                'type'         => 'assigned',
                'description'  => 'Assigned during employee onboarding (migrated from the HR onboarding asset list; the issuer was never recorded).',
                'employee_id'  => $employeeId,
                'performed_at' => $assignedAt,
                'performed_by' => null,
                'created_at'   => $row->created_at,
                'updated_at'   => $row->updated_at,
            ]);

            if ($returned) {
                DB::table('inventory_asset_events')->insert([
                    'tenant_id'    => $tenantId,
                    'asset_id'     => $assetId,
                    'type'         => 'returned',
                    'description'  => 'Returned (migrated from the HR onboarding asset list).',
                    'employee_id'  => $employeeId,
                    'performed_at' => $row->returned_date ?: $row->updated_at,
                    'performed_by' => null,
                    'created_at'   => $row->updated_at,
                    'updated_at'   => $row->updated_at,
                ]);
            }
        }

        Schema::drop('hr_employee_onboarding_assets');
    }

    /**
     * Rebuild the table and hand the migrated rows back, so a rollback does not
     * strand the data on the Inventory side.
     */
    public function down(): void
    {
        if (Schema::hasTable('hr_employee_onboarding_assets')) {
            return;
        }

        Schema::create('hr_employee_onboarding_assets', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('onboarding_id');
            $table->string('asset_type', 120);
            $table->string('asset_tag_serial', 120)->nullable();
            $table->date('issued_date')->nullable();
            $table->string('condition', 60)->nullable();
            $table->date('returned_date')->nullable();
            $table->string('status', 30)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();
        });

        $migrated = DB::table('inventory_assets')->where('code', 'like', 'HR-ONB-%')->get();

        foreach ($migrated as $asset) {
            $id = (int) str_replace('HR-ONB-', '', $asset->code);
            $onboardingId = DB::table('hr_employee_onboardings')
                ->where('employee_id', $asset->assigned_employee_id)
                ->value('id');

            $returnedAt = DB::table('inventory_asset_events')
                ->where('asset_id', $asset->id)->where('type', 'returned')->value('performed_at');
            $assignedAt = DB::table('inventory_asset_events')
                ->where('asset_id', $asset->id)->where('type', 'assigned')->value('performed_at');

            DB::table('hr_employee_onboarding_assets')->insert([
                'id'               => $id,
                'tenant_id'        => $asset->tenant_id,
                'onboarding_id'    => $onboardingId ?: 0,
                'asset_type'       => $asset->name,
                'asset_tag_serial' => $asset->serial_no,
                'issued_date'      => $assignedAt,
                'condition'        => $asset->condition,
                'returned_date'    => $returnedAt,
                'status'           => $returnedAt ? 'Returned' : 'Allocated',
                'remarks'          => $asset->note,
                'created_at'       => $asset->created_at,
                'updated_at'       => $asset->updated_at,
            ]);

            DB::table('inventory_asset_events')->where('asset_id', $asset->id)->delete();
            DB::table('inventory_assets')->where('id', $asset->id)->delete();
        }
    }
};
