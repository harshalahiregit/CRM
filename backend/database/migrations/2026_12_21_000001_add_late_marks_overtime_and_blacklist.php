<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three things the business asked for that the system recorded but never acted on.
 *
 * ── Late marks ──
 *
 * HR set the policy out on 5 Sep — 15 minutes' grace on a 09:30 start, half a
 * day at the third late mark, a further deduction at the fifth — and the
 * settings were added to match it exactly. The comment above them said
 * "Nothing enforces these yet", and that stayed true: no code outside the
 * registry read the keys. A workspace could switch the policy on, watch late
 * marks accumulate all month, and never see a rupee deducted.
 *
 * The count and the money are stored on the record rather than recomputed on
 * demand, for the same reason every other payroll figure is frozen: an
 * attendance correction filed in October must not silently change what August
 * paid. `late_mark_reason` travels with them so a payslip can answer "why is my
 * salary short?" without anybody re-running payroll.
 *
 * ── Overtime ──
 *
 * Named as an allowance head in the same conversation ("अलाउंस में और एक हेड
 * आएगा ओवरटाइम करके"). Attendance has recorded `overtime_hours` per day since
 * it was built and payroll has never read the column. Hours and money are kept
 * apart so the payslip can show the rate it was paid at, which is the first
 * thing anybody queries.
 *
 * ── Blacklisting ──
 *
 * Distinct from deactivating, and the difference is the whole point: a
 * deactivated employee can be reactivated when they rejoin, a blacklisted one
 * is someone the business has decided will not be re-engaged. Without the flag
 * the only way to express it is a note in a field nobody reads at hiring time.
 * `hold_salary` already exists and is a different thing again — a temporary
 * withholding for somebody still employed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_payroll_records')) {
            Schema::table('hr_payroll_records', function (Blueprint $t) {
                // Frozen at process time, like every other figure on this row.
                $t->unsignedSmallInteger('late_marks')->default(0)->after('leave_days');
                $t->decimal('late_mark_deduction', 12, 2)->default(0)->after('late_marks');
                $t->string('late_mark_reason')->nullable()->after('late_mark_deduction');

                // Hours and money separately: the payslip shows the rate.
                $t->decimal('overtime_hours', 8, 2)->default(0)->after('late_mark_reason');
                $t->decimal('overtime_amount', 12, 2)->default(0)->after('overtime_hours');
            });
        }

        if (Schema::hasTable('hr_employees') && ! Schema::hasColumn('hr_employees', 'blacklisted')) {
            Schema::table('hr_employees', function (Blueprint $t) {
                $t->boolean('blacklisted')->default(false)->after('hold_salary');
                $t->string('blacklist_reason', 500)->nullable()->after('blacklisted');
                $t->timestamp('blacklisted_at')->nullable()->after('blacklist_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_payroll_records')) {
            Schema::table('hr_payroll_records', fn (Blueprint $t) => $t->dropColumn([
                'late_marks', 'late_mark_deduction', 'late_mark_reason',
                'overtime_hours', 'overtime_amount',
            ]));
        }

        if (Schema::hasTable('hr_employees') && Schema::hasColumn('hr_employees', 'blacklisted')) {
            Schema::table('hr_employees', fn (Blueprint $t) => $t->dropColumn([
                'blacklisted', 'blacklist_reason', 'blacklisted_at',
            ]));
        }
    }
};
