<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Year-to-date tax context frozen onto each payroll record.
 *
 * These are not new calculations — they are the inputs the month's TDS was
 * actually computed from. Without them, "why was ₹X deducted in September?" can
 * only be answered by replaying the whole year against today's rules, which will
 * have changed. Every column is additive with a safe default, so records written
 * before this migration keep their existing meaning.
 */
return new class extends Migration
{
    private array $decimals = [
        'ytd_taxable_earnings',   // taxable pay THIS year up to and including this month
        'ytd_tds',                // TDS deducted this year up to and including this month
        'annual_taxable_income',  // after deductions/exemptions — the figure tax was computed on
        'annual_tax_liability',   // the full year's tax the month's deduction was derived from
    ];

    public function up(): void
    {
        Schema::table('hr_payroll_records', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_payroll_records', 'financial_year')) {
                $table->string('financial_year', 9)->nullable();
            }
            // 'old' | 'new' | null when no TDS rule applied.
            if (! Schema::hasColumn('hr_payroll_records', 'tax_regime')) {
                $table->string('tax_regime', 10)->nullable();
            }
            foreach ($this->decimals as $c) {
                if (! Schema::hasColumn('hr_payroll_records', $c)) {
                    $table->decimal($c, 14, 2)->default(0);
                }
            }
        });

        Schema::table('hr_payroll_records', function (Blueprint $table) {
            $table->index(['tenant_id', 'employee_id', 'financial_year'], 'hr_payroll_rec_fy_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_records', function (Blueprint $table) {
            $table->dropIndex('hr_payroll_rec_fy_idx');
        });
    }
};
