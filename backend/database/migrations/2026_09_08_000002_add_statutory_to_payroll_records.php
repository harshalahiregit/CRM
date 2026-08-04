<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the statutory split alongside the existing payroll totals.
 *
 * Every column is additive and defaults to 0, so records written before this
 * migration keep their meaning: the pre-existing gross/deduction/net totals are
 * untouched, and an un-configured tenant simply records zeros.
 *
 * Employer-side contributions (PF employer, ESIC employer) are recorded but are
 * NOT part of total_deductions — they are a cost to the company, not a deduction
 * from the employee, and conflating them would understate net pay.
 */
return new class extends Migration
{
    private array $columns = [
        'pf_wages',        // the wage base PF was computed on (after any ceiling)
        'pf_employee',
        'pf_employer',
        'eps_employer',    // the EPS slice of the employer's PF share
        'esic_wages',
        'esic_employee',
        'esic_employer',
        'pt_amount',
        'tds_amount',
        'bonus_amount',
        'gratuity_amount',
        'taxable_earnings',
        'statutory_deductions', // pf_employee + esic_employee + pt + tds
    ];

    public function up(): void
    {
        Schema::table('hr_payroll_records', function (Blueprint $table) {
            foreach ($this->columns as $c) {
                if (! Schema::hasColumn('hr_payroll_records', $c)) {
                    $table->decimal($c, 14, 2)->default(0);
                }
            }
            // Which rules actually applied, for audit ("why is PF zero this month?").
            if (! Schema::hasColumn('hr_payroll_records', 'statutory_meta')) {
                $table->json('statutory_meta')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_records', function (Blueprint $table) {
            foreach ($this->columns as $c) {
                if (Schema::hasColumn('hr_payroll_records', $c)) {
                    $table->dropColumn($c);
                }
            }
            if (Schema::hasColumn('hr_payroll_records', 'statutory_meta')) {
                $table->dropColumn('statutory_meta');
            }
        });
    }
};
