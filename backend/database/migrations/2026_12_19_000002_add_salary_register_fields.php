<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns a salary register prints that the employee record could not supply.
 *
 * Taken from the company's own July 2026 register — 86 columns of it. Most were
 * already held; these eleven were not, and a register cannot simply omit a column
 * because we have nowhere to keep it.
 *
 *   division / unit / zone
 *       Three grouping levels ABOVE department, which the register carries and
 *       every payroll report subtotals by. A company with one office does not
 *       notice; one with three sites cannot produce a per-site register without
 *       them.
 *
 *   category
 *       The skill category — the register reads "SEMI SKILLED". This is not
 *       decoration: minimum-wage floors are set per skill category per state, so
 *       it decides whether a wage is legal.
 *
 *   pay_mode / bank_customer_id / company_bank_name
 *       How the person is actually paid. A bank transfer file needs the account,
 *       the IFSC and which of the company's OWN accounts it leaves from; a cash
 *       or cheque payee needs none of them but must not silently land in the
 *       transfer file.
 *
 *   lin_number
 *       Labour Identification Number, issued under the Shram Suvidha portal and
 *       quoted on unified filings.
 *
 *   exit_date
 *       The register's DOLeft. hr_employees has joining_date and a status, but
 *       the leaving DATE lived only in the exit-management tables — so a
 *       register for the month somebody left could not print it.
 *
 *   hold_salary
 *       Pay withheld this month — an exit under investigation, an unreturned
 *       laptop. Without it the only way to withhold is to leave the person out
 *       of the run, which loses them from the register entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $t) {
            // Grouping above department, in the order the register nests them.
            $t->string('division', 120)->nullable()->after('department');
            $t->string('unit', 120)->nullable()->after('division');
            $t->string('zone', 120)->nullable()->after('unit');
            // Minimum wage is set per skill category, so this decides legality.
            $t->string('category', 80)->nullable()->after('zone');
            // The register's DOLeft.
            $t->date('exit_date')->nullable()->after('confirmation_date');
            $t->boolean('hold_salary')->default(false)->after('status');
        });

        if (Schema::hasTable('hr_employee_details')) {
            Schema::table('hr_employee_details', function (Blueprint $t) {
                $t->string('pay_mode', 30)->nullable()->after('bank_account_type');
                $t->string('bank_customer_id', 60)->nullable()->after('pay_mode');
                // Which of the COMPANY's accounts the salary leaves from.
                $t->string('company_bank_name', 150)->nullable()->after('bank_customer_id');
                $t->string('lin_number', 40)->nullable()->after('uan_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_employees')) {
            Schema::table('hr_employees', fn (Blueprint $t) => $t->dropColumn([
                'division', 'unit', 'zone', 'category', 'exit_date', 'hold_salary',
            ]));
        }

        if (Schema::hasTable('hr_employee_details')) {
            Schema::table('hr_employee_details', fn (Blueprint $t) => $t->dropColumn([
                'pay_mode', 'bank_customer_id', 'company_bank_name', 'lin_number',
            ]));
        }
    }
};
