<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payroll run becomes a WORKFLOW instead of a button.
 *
 * Until now `process()` swept every employee with an active salary, computed
 * them, and jumped straight to Completed. That is one person's decision with no
 * record of who agreed to it, which is not how anybody actually pays salaries —
 * and it is not what was asked for. The 3 Sep walkthrough specified six stages
 * with three different people signing at different points:
 *
 *     select the month → select WHICH employees → compute a draft →
 *     HR adds or deducts with a written reason → reporting manager approves →
 *     accounts marks each person paid → the employee sees a payslip
 *
 * ── Why `stage` sits BESIDE `status` rather than replacing it ──
 *
 * `status` (Draft/Processing/Completed/Cancelled) is load-bearing: it is what
 * makes a finalized run immutable, and PayrollWorkStateTest asserts a processed
 * run reaches Completed. Redefining it would have meant changing every consumer
 * at the same time as adding a workflow, and getting either wrong silently
 * changes what people are paid.
 *
 * So `status` keeps its exact present meaning — has this run been computed, and
 * is it locked — and `stage` carries the new question of WHERE IN THE APPROVAL
 * CHAIN it is. They answer different things and both are needed: a run can be
 * computed (status Completed) and still be sitting unapproved (stage Approve).
 * The UI shows the stage; the immutability logic keeps reading the status.
 *
 * ── The three new tables ──
 *
 *   hr_payroll_run_employees
 *       WHO is in this run. A row means selected. Its absence is the whole
 *       point: today there is no way to run payroll for 40 of 50 people, so a
 *       new joiner mid-approval forces you to discard the run and start again.
 *       `blocked_reason` records why somebody could not be selected (no bank
 *       account, no PAN) so the pre-check can explain itself rather than just
 *       omitting them.
 *
 *   hr_payroll_adjustments
 *       The "HR ka extra column". An off-cycle reimbursement, a recovery, an
 *       arrear — money that is genuinely outside the salary structure and known
 *       only to HR. It was specified WITH a mandatory reason and an optional
 *       upload, and that is the important half: an unexplained ±₹1,000 on
 *       somebody's salary is indistinguishable from a mistake six months later.
 *       Stored as its own rows, never folded into the frozen snapshot, so the
 *       structure figures stay exactly as computed and the adjustment stays
 *       attributable to the person who made it.
 *
 *   payment_status on the record
 *       Accounts pays in batches and some transfers fail. Without a per-person
 *       status the only answer to "did Priya get paid?" is "the run says
 *       Completed", which is a statement about arithmetic, not about money.
 *
 * `payslip_visible` defaults to FALSE deliberately — the brief was explicit that
 * payslips are hidden until HR releases them, because a payslip visible before
 * the transfer clears generates a queue of questions HR cannot yet answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_payroll_runs')) {
            Schema::table('hr_payroll_runs', function (Blueprint $t) {
                // Where in the approval chain this run stands. Pre-check is the
                // entry point, matching a freshly created Draft run.
                $t->string('stage', 20)->default('Pre-check')->after('status');

                // Level 2 — the reporting manager. `approval_note` carries the
                // back-and-forth: a rejection with no reason gets re-submitted
                // unchanged, which is how approval loops become infinite.
                $t->unsignedBigInteger('approved_by')->nullable()->after('processed_by');
                $t->timestamp('approved_at')->nullable()->after('processed_at');
                $t->text('approval_note')->nullable()->after('approved_at');

                // Level 3 — accounts. Disbursed means the money left, not that
                // the arithmetic finished.
                $t->unsignedBigInteger('disbursed_by')->nullable()->after('approval_note');
                $t->timestamp('disbursed_at')->nullable()->after('disbursed_by');

                // The bank total, which is NOT total_net: net_salary is the
                // frozen structure figure and excludes the statutory split,
                // this period's variable earnings, loan instalments and
                // adjustments. Keeping them in separate columns is what let a
                // bank advice pay PF to both the government and the employee.
                $t->decimal('total_payable', 14, 2)->default(0)->after('total_net');
            });
        }

        // WHO is in the run. Rows are the selection; no row means not selected.
        if (! Schema::hasTable('hr_payroll_run_employees')) {
            Schema::create('hr_payroll_run_employees', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('payroll_run_id');
                $t->unsignedBigInteger('employee_id');
                // Set when the pre-check refuses somebody: "No bank account",
                // "PAN missing". Null for anybody selectable.
                $t->string('blocked_reason')->nullable();
                $t->timestamps();

                $t->unique(['payroll_run_id', 'employee_id'], 'payroll_run_employee_unique');
                $t->index(['tenant_id', 'payroll_run_id']);
            });
        }

        // HR's additions and deductions, each with a reason.
        if (! Schema::hasTable('hr_payroll_adjustments')) {
            Schema::create('hr_payroll_adjustments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('payroll_run_id');
                $t->unsignedBigInteger('payroll_record_id');
                $t->unsignedBigInteger('employee_id');
                // 'Addition' | 'Deduction'. Stored rather than inferred from the
                // sign so a zero-amount row is still unambiguous, and so a
                // report can group by intent without reading arithmetic.
                $t->string('type', 12);
                $t->decimal('amount', 12, 2)->default(0);
                // Not nullable. The reason is the reason this table exists.
                $t->string('reason', 500);
                $t->string('attachment_path')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();

                $t->index(['tenant_id', 'payroll_run_id']);
                $t->index('payroll_record_id');
            });
        }

        if (Schema::hasTable('hr_payroll_records')) {
            Schema::table('hr_payroll_records', function (Blueprint $t) {
                // Per-person payment tracking for accounts.
                $t->string('payment_status', 12)->default('Pending')->after('status');
                $t->timestamp('paid_at')->nullable()->after('payment_status');
                $t->string('payment_note')->nullable()->after('paid_at');

                // Hidden until HR releases it. See the docblock above.
                $t->boolean('payslip_visible')->default(false)->after('payment_note');

                // Net of HR's adjustments for this record. A cached sum of
                // hr_payroll_adjustments so the payable figure is one column
                // read rather than a join on every row of the bank advice.
                $t->decimal('adjustment_total', 12, 2)->default(0)->after('loan_deduction');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_payroll_runs')) {
            Schema::table('hr_payroll_runs', fn (Blueprint $t) => $t->dropColumn([
                'stage', 'approved_by', 'approved_at', 'approval_note',
                'disbursed_by', 'disbursed_at', 'total_payable',
            ]));
        }

        Schema::dropIfExists('hr_payroll_adjustments');
        Schema::dropIfExists('hr_payroll_run_employees');

        if (Schema::hasTable('hr_payroll_records')) {
            Schema::table('hr_payroll_records', fn (Blueprint $t) => $t->dropColumn([
                'payment_status', 'paid_at', 'payment_note', 'payslip_visible', 'adjustment_total',
            ]));
        }
    }
};
