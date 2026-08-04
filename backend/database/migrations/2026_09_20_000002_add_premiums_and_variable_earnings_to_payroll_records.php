<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #30 (WCP + Mediclaim) and #31 (commission / incentive) on the frozen record.
 *
 * All five columns default to 0, so every record written before this migration —
 * and every tenant that configures none of it — reads back exactly as it did.
 * That is what keeps the change backward compatible: nothing is recomputed, and a
 * zero is indistinguishable from the previous absence of the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_records', function (Blueprint $table) {
            // #30 — split so a payslip can show the employee's share and a cost
            // report the company's, without either inferring the other.
            foreach ([
                'wcp_employee'       => 'statutory_deductions',
                'wcp_employer'       => 'wcp_employee',
                'mediclaim_employee' => 'wcp_employer',
                'mediclaim_employer' => 'mediclaim_employee',
            ] as $column => $after) {
                if (! Schema::hasColumn('hr_payroll_records', $column)) {
                    $table->decimal($column, 12, 2)->default(0)->after($after);
                }
            }

            // #31 — commission/incentive paid for THIS period. Kept out of
            // `gross_salary`, which is the frozen structure snapshot every
            // existing consumer already reads; the payable figure adds the two.
            if (! Schema::hasColumn('hr_payroll_records', 'variable_earnings')) {
                $table->decimal('variable_earnings', 12, 2)->default(0)->after('loan_deduction');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_records', function (Blueprint $table) {
            $table->dropColumn([
                'wcp_employee', 'wcp_employer', 'mediclaim_employee',
                'mediclaim_employer', 'variable_earnings',
            ]);
        });
    }
};
