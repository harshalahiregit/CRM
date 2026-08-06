<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-component breakdown for a processed payroll record.
 *
 * Until now a record stored five totals and nothing else, so a payslip could not
 * show a breakup and a statutory amount had nowhere to sit. Each line is a frozen
 * copy — component name and code are denormalised on purpose so renaming or
 * deleting a master never rewrites history.
 *
 * `source` distinguishes a line derived from the salary structure from one the
 * statutory engine produced, which is what lets the UI group "Earnings /
 * Deductions / Statutory" without re-deriving anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_record_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('payroll_record_id');
            // Null once the master is deleted — the name/code snapshot still stands.
            $table->unsignedBigInteger('component_id')->nullable();

            $table->string('code', 40)->nullable();
            $table->string('name', 150);
            $table->string('type', 20);              // Earning | Deduction | Benefit
            $table->string('source', 20)->default('structure'); // structure | statutory
            $table->decimal('amount', 14, 2)->default(0);

            $table->boolean('taxable')->default(false);
            $table->boolean('pf_applicable')->default(false);
            $table->boolean('esic_applicable')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'payroll_record_id'], 'hr_pay_lines_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_record_lines');
    }
};
