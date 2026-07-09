<?php

namespace App\Services\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrOnboarding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OnboardingService
{
    private const STEPS = [
        'doc_verification', 'joining_confirmed', 'emp_id_generated',
        'dept_assigned', 'manager_assigned', 'record_created',
    ];

    public function list(int $tenantId, array $filters): Collection
    {
        $query = HrOnboarding::where('tenant_id', $tenantId);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->get();
    }

    public function create(array $data, int $tenantId): HrOnboarding
    {
        $data['tenant_id'] = $tenantId;
        $record = HrOnboarding::create([...$data, 'status' => 'Pending']);

        if (! empty($data['candidate_id'])) {
            $candidate = HrCandidate::find($data['candidate_id']);
            if ($candidate && $candidate->email) {
                Mail::to($candidate->email)->send(
                    new \App\Mail\OnboardingWelcomeMail($record)
                );
            }
        }

        Log::channel('hr')->info('Onboarding record created', ['onboarding_id' => $record->id, 'tenant_id' => $tenantId]);

        return $record;
    }

    public function toggleChecklist(HrOnboarding $onboarding, array $checklist): HrOnboarding
    {
        $onboarding->update(['document_checklist' => $checklist]);

        Log::channel('hr')->info('Onboarding checklist updated', ['onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id]);

        return $onboarding->fresh();
    }

    public function toggleStep(HrOnboarding $onboarding, string $step): HrOnboarding
    {
        $col = 'step_'.$step;
        $onboarding->update([$col => ! $onboarding->$col]);

        $steps = [
            $onboarding->step_doc_verification,
            $onboarding->step_joining_confirmed,
            $onboarding->step_emp_id_generated,
            $onboarding->step_dept_assigned,
            $onboarding->step_manager_assigned,
            $onboarding->step_record_created,
        ];
        $doneCount = count(array_filter($steps));
        $status = $doneCount === 0 ? 'Pending' : ($doneCount === 6 ? 'Completed' : 'In Progress');
        $onboarding->update(['status' => $status]);

        if ($status === 'Completed' && ! HrEmployee::where('candidate_id', $onboarding->candidate_id)->exists()) {
            $empCode = 'SNE-'.date('Y').'-'.str_pad(HrEmployee::where('tenant_id', $onboarding->tenant_id)->count() + 1, 3, '0', STR_PAD_LEFT);
            $employee = HrEmployee::create([
                'tenant_id'              => $onboarding->tenant_id,
                'employee_code'          => $empCode,
                'candidate_id'           => $onboarding->candidate_id,
                'onboarding_id'          => $onboarding->id,
                'name'                   => $onboarding->candidate_name,
                'department'             => $onboarding->department,
                'designation'            => $onboarding->position,
                'reporting_manager_name' => $onboarding->reporting_manager_name,
                'joining_date'           => $onboarding->joining_date,
                'status'                 => 'Active',
            ]);

            Log::channel('hr')->info('Employee auto-created from completed onboarding', ['onboarding_id' => $onboarding->id, 'employee_id' => $employee->id, 'tenant_id' => $onboarding->tenant_id]);
        }

        Log::channel('hr')->info('Onboarding step toggled', ['onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id, 'step' => $step, 'status' => $status]);

        return $onboarding->fresh();
    }

    public function destroy(HrOnboarding $onboarding): void
    {
        $onboarding->delete();

        Log::channel('hr')->info('Onboarding record deleted', ['onboarding_id' => $onboarding->id, 'tenant_id' => $onboarding->tenant_id]);
    }
}
