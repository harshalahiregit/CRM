<?php

namespace App\Services\Notifications\Reminders;

use Illuminate\Support\Facades\DB;

/**
 * Reminder source: Loan → Approval Pending.
 *
 * Read-only over hr_employee_loans — submitted loans awaiting a decision, due
 * immediately and repeating daily until decided. The row leaves the query once
 * the loan is approved, rejected or cancelled, so the reminders stop by
 * themselves.
 *
 * Somebody waiting on a loan is waiting on money, and before this nothing
 * chased the approver at all. Uses the existing ladder and escalation rather
 * than a mechanism of its own.
 */
class LoanApprovalPendingSource implements ReminderSource
{
    public function module(): string
    {
        return 'Loan';
    }

    public function event(): string
    {
        return 'Approval Pending';
    }

    public function due(int $tenantId): iterable
    {
        if (! DB::getSchemaBuilder()->hasTable('hr_employee_loans')) {
            return;
        }

        $rows = DB::table('hr_employee_loans as l')
            ->join('hr_employees as e', 'l.employee_id', '=', 'e.id')
            ->where('l.tenant_id', $tenantId)
            ->where('l.status', 'Submitted')
            ->get(['l.id', 'l.principal', 'e.name', 'e.department']);

        foreach ($rows as $r) {
            yield [
                'entity_type'     => 'HrEmployeeLoan',
                'entity_id'       => (int) $r->id,
                'due_date'        => now()->toDateString(),
                'recipient_roles' => ['hr'],
                'context'         => [
                    'employee'   => $r->name,
                    'department' => $r->department,
                    'amount'     => '₹'.number_format((float) $r->principal, 2),
                ],
                'action_url'   => '/app/hr/loans',
                'action_label' => 'Review Loan',
            ];
        }
    }
}
