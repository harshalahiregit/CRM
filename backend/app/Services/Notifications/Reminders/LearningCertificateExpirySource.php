<?php

namespace App\Services\Notifications\Reminders;

use Illuminate\Support\Facades\DB;

/**
 * Reminder source: Learning → Certificate Expiring. Read-only over
 * hr_training_certificates — issued certificates with a future expiry, keyed on the
 * expiry date. Expired/replaced certificates drop out of the query automatically.
 */
class LearningCertificateExpirySource implements ReminderSource
{
    public function module(): string
    {
        return 'Learning';
    }

    public function event(): string
    {
        return 'Certificate Expiring';
    }

    public function due(int $tenantId): iterable
    {
        if (! DB::getSchemaBuilder()->hasTable('hr_training_certificates')) {
            return;
        }
        $rows = DB::table('hr_training_certificates as c')
            ->join('hr_employee_trainings as et', 'c.employee_training_id', '=', 'et.id')
            ->join('hr_employees as e', 'et.employee_id', '=', 'e.id')
            ->where('c.tenant_id', $tenantId)->where('c.status', 'Issued')
            ->whereNotNull('c.expiry_date')->whereDate('c.expiry_date', '>=', now()->toDateString())
            ->get(['c.id', 'c.expiry_date', 'e.name', 'e.department']);

        foreach ($rows as $r) {
            yield [
                'entity_type' => 'HrTrainingCertificate',
                'entity_id' => (int) $r->id,
                'due_date' => $r->expiry_date,
                'recipient_roles' => ['hr'],
                'context' => ['employee' => $r->name, 'department' => $r->department],
                'action_url' => '/app/hr/learning-development',
                'action_label' => 'Open Certificate',
            ];
        }
    }
}
