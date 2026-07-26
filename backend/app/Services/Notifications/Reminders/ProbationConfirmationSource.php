<?php

namespace App\Services\Notifications\Reminders;

use Illuminate\Support\Facades\DB;

/**
 * Reminder source: Probation → Confirmation Pending. Read-only over existing
 * probation tables — active/extended probations that have no confirmation yet,
 * keyed on the probation end date. Stops automatically once the probation is
 * confirmed/closed (those rows drop out of the query).
 */
class ProbationConfirmationSource implements ReminderSource
{
    public function module(): string
    {
        return 'Probation';
    }

    public function event(): string
    {
        return 'Confirmation Pending';
    }

    public function due(int $tenantId): iterable
    {
        $rows = DB::table('hr_employee_probations as ep')
            ->join('hr_employees as e', 'ep.employee_id', '=', 'e.id')
            ->leftJoin('hr_probation_confirmations as c', function ($j) {
                $j->on('c.probation_id', '=', 'ep.id')->where('c.status', '=', 'Confirmed');
            })
            ->where('ep.tenant_id', $tenantId)
            ->whereIn('ep.current_status', ['Active', 'Extended'])
            ->whereNull('c.id')
            ->whereNotNull('ep.probation_end_date')
            ->get(['ep.id', 'ep.probation_end_date', 'e.name', 'e.department', 'e.designation']);

        foreach ($rows as $r) {
            yield [
                'entity_type' => 'HrEmployeeProbation',
                'entity_id' => (int) $r->id,
                'due_date' => $r->probation_end_date,
                'recipient_roles' => ['hr'],
                'context' => ['employee' => $r->name, 'department' => $r->department, 'designation' => $r->designation],
                'action_url' => '/app/hr/probation-management',
                'action_label' => 'Open Probation',
            ];
        }
    }
}
