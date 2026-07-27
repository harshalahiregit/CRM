<?php

namespace App\Services\Notifications\Reminders;

use Illuminate\Support\Facades\DB;

/**
 * Reminder source: Leave → Pending Approval. Read-only over hr_leave_applications
 * — submitted applications awaiting an HR decision, due immediately and repeating
 * daily until decided (the row leaves the query once approved/rejected/cancelled).
 */
class LeavePendingApprovalSource implements ReminderSource
{
    public function module(): string
    {
        return 'Leave';
    }

    public function event(): string
    {
        return 'Pending Approval';
    }

    public function due(int $tenantId): iterable
    {
        if (! DB::getSchemaBuilder()->hasTable('hr_leave_applications')) {
            return;
        }
        $rows = DB::table('hr_leave_applications as a')->join('hr_employees as e', 'a.employee_id', '=', 'e.id')
            ->where('a.tenant_id', $tenantId)->where('a.status', 'Submitted')
            ->get(['a.id', 'a.from_date', 'e.name', 'e.department']);

        foreach ($rows as $r) {
            yield [
                'entity_type' => 'HrLeaveApplication',
                'entity_id' => (int) $r->id,
                'due_date' => now()->toDateString(),
                'recipient_roles' => ['hr'],
                'context' => ['employee' => $r->name, 'department' => $r->department, 'date' => $r->from_date],
                'action_url' => '/app/hr/leave-management',
                'action_label' => 'Review Leave',
            ];
        }
    }
}
