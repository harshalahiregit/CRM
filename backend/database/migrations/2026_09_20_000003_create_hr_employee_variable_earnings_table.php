<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comment #31 — "Earnings: Commissions/Incentives for employees".
 *
 * A commission is not a salary structure line. A structure describes what someone
 * earns EVERY month; a commission is earned in one month and not the next, and
 * putting it on the structure would either pay it forever or force the structure
 * to be edited twice a month.
 *
 * So this table holds a per-period amount against an existing
 * `hr_salary_components` row. The component master already carries everything
 * that decides how the money is treated — `taxable`, `pf_applicable`,
 * `esic_applicable` — so a tenant defines "Sales Commission" once as an Earning
 * component and the statutory engine handles it with no special case. That is
 * what "configurable earning components" means here: no new component concept,
 * just a variable amount against the existing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_variable_earnings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('component_id');

            // 'YYYY-MM' — the payroll period this is paid in, matching the format
            // payroll already uses everywhere else.
            $table->string('period', 7);
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable();   // deal id, target name, etc.
            $table->text('remarks')->nullable();

            // Approved money only reaches payroll. A commission is discretionary,
            // so an unapproved figure must never be paid by a run that happens to
            // execute first.
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            // Set when a payroll run picks it up, so the same commission cannot be
            // paid twice — and so a reprocessed run can release it again.
            $table->unsignedBigInteger('payroll_record_id')->nullable()->index();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'period']);
            $table->index(['tenant_id', 'status']);

            $table->foreign('employee_id')->references('id')->on('hr_employees')->cascadeOnDelete();
            $table->foreign('component_id')->references('id')->on('hr_salary_components')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_variable_earnings');
    }
};
