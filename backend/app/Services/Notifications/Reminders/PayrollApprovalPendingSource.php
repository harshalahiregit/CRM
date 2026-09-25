<?php

namespace App\Services\Notifications\Reminders;

use Illuminate\Support\Facades\DB;

/**
 * Reminder source: Payroll → Approval Pending.
 *
 * Read-only over hr_payroll_runs — a run sitting on the Approve stage, due
 * immediately and repeating daily until somebody signs it. The row leaves the
 * query the moment the run moves to Disburse or Paid, so nothing has to
 * remember to stop reminding.
 *
 * The existing reminder/escalation mechanism does the rest: the ladder in
 * config/hr_notifications.php escalates an unsigned run from hr to hr_manager
 * to department_head to admin as the days pass. Nothing new is invented here.
 *
 * Addressed to the hr role because that is the only one the inbox can express
 * today — see HrEventNotifier::toHrQueue(). Distinct Finance/Accounts targeting
 * needs NotificationRepository::visibleTo() to match recipient_role, which is
 * deliberately out of scope for this phase.
 */
class PayrollApprovalPendingSource implements ReminderSource
{
    public function module(): string
    {
        return 'Payroll';
    }

    public function event(): string
    {
        return 'Approval Pending';
    }

    public function due(int $tenantId): iterable
    {
        if (! DB::getSchemaBuilder()->hasTable('hr_payroll_runs')) {
            return;
        }

        $rows = DB::table('hr_payroll_runs')
            ->where('tenant_id', $tenantId)
            ->where('stage', 'Approve')
            ->whereNotIn('status', ['Cancelled'])
            ->get(['id', 'payroll_month', 'payroll_year', 'total_employees', 'total_payable']);

        foreach ($rows as $r) {
            $period = trim(date('F', mktime(0, 0, 0, (int) $r->payroll_month, 1)).' '.$r->payroll_year);

            yield [
                'entity_type'     => 'HrPayrollRun',
                'entity_id'       => (int) $r->id,
                'due_date'        => now()->toDateString(),
                'recipient_roles' => ['hr'],
                'context'         => [
                    'period'    => $period,
                    'employees' => (int) $r->total_employees,
                    'amount'    => '₹'.number_format((float) $r->total_payable, 2),
                ],
                'action_url'   => '/app/hr/payroll',
                'action_label' => 'Review Payroll',
            ];
        }
    }
}
