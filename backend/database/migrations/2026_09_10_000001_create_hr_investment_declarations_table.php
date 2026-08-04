<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's tax declaration for one financial year.
 *
 * One row per employee per year per tenant. The regime lives here rather than on
 * the employee because it is a per-year election, not a permanent attribute.
 *
 * Previous-employer figures are stored on the declaration for the same reason:
 * they belong to the year being computed, and they are what the employee (not
 * payroll) reports. They are nullable — most employees have none.
 *
 * `hra` is JSON because its inputs (rent, city, months) are a small fixed group
 * that is only ever read together, and it is an exemption rather than a Chapter
 * VI-A deduction, so it does not belong in the items table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_investment_declarations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id')->index();

            // "2026-2027" — canonical, produced by FinancialYear::label().
            $table->string('financial_year', 9);
            // 'old' | 'new'. Which deductions each allows is CONFIGURED, not assumed.
            $table->string('regime', 10)->default('new');
            $table->string('status', 20)->default('Draft');   // Draft|Submitted|Verified|Rejected

            // Reported by the employee for a mid-year join. Nullable = none reported.
            $table->decimal('previous_employer_income', 14, 2)->nullable();
            $table->decimal('previous_employer_tds', 14, 2)->nullable();
            $table->decimal('previous_employer_pf', 14, 2)->nullable();
            $table->decimal('previous_employer_pt', 14, 2)->nullable();

            // HRA exemption inputs — {rent_paid_annual, metro, months, landlord_pan}
            $table->json('hra')->nullable();

            // Rolled up from the items at save time so listings need no join.
            $table->decimal('declared_total', 14, 2)->default(0);
            $table->decimal('verified_total', 14, 2)->default(0);

            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // One declaration per employee per year. Enforced in the DB because a
            // duplicate would silently split a year's deductions across two rows.
            $table->unique(['tenant_id', 'employee_id', 'financial_year'], 'hr_inv_decl_unique');
            $table->index(['tenant_id', 'financial_year', 'status'], 'hr_inv_decl_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_investment_declarations');
    }
};
