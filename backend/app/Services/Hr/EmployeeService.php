<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrOnboarding;
use App\Models\User;
use App\Repositories\Hr\EmployeeRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class EmployeeService
{
    public function __construct(private EmployeeRepository $employeeRepository)
    {
    }

    public function list(int $tenantId, array $filters): LengthAwarePaginator
    {
        return $this->employeeRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, int $tenantId): HrEmployee
    {
        $data['tenant_id'] = $tenantId;
        $empCode = 'SNE-'.date('Y').'-'.str_pad(HrEmployee::where('tenant_id', $tenantId)->count() + 1, 3, '0', STR_PAD_LEFT);

        $employee = HrEmployee::create([...$data, 'employee_code' => $empCode]);

        // Milestone that closes the recruitment → employee lifecycle timeline.
        $employee->recordAudit('Employee Created', null, null, ['employee_code' => $employee->employee_code]);

        Log::channel('hr')->info('Employee created', ['employee_id' => $employee->id, 'tenant_id' => $tenantId]);

        return $employee;
    }

    public function update(HrEmployee $employee, array $data, ?User $actor = null): HrEmployee
    {
        $before = $employee->only(['department', 'designation', 'status', 'reporting_manager_name']);

        $employee->update($data);

        // Audit every edit — with a descriptive action for the notable lifecycle
        // events (department change, deactivation, manager change).
        [$action, $meta] = $this->describeChange($before, $employee);
        $employee->recordAudit($action, $actor, null, $meta);

        Log::channel('hr')->info('Employee updated', ['employee_id' => $employee->id, 'tenant_id' => $employee->tenant_id, 'action' => $action]);

        return $employee;
    }

    /** Map a set of changed fields to a human lifecycle event + metadata. */
    private function describeChange(array $before, HrEmployee $after): array
    {
        $meta = [];
        foreach (['department', 'designation', 'status', 'reporting_manager_name'] as $f) {
            if (($before[$f] ?? null) !== $after->{$f}) {
                $meta[$f] = ['from' => $before[$f] ?? null, 'to' => $after->{$f}];
            }
        }

        if (isset($meta['status']) && $after->status === 'Inactive') {
            return ['Employee Exit / Deactivated', $meta];
        }
        if (isset($meta['department'])) {
            return ['Department Change', $meta];
        }
        if (isset($meta['designation'])) {
            return ['Designation Changed', $meta];
        }
        if (isset($meta['reporting_manager_name'])) {
            return ['Reporting Manager Changed', $meta];
        }

        return ['Employee Updated', $meta];
    }

    /**
     * Full enterprise profile — aggregates recruitment, onboarding, offer and
     * document data already stored elsewhere. No duplicate tables: everything is
     * read through the existing candidate → onboarding → offer relationships.
     */
    public function profile(HrEmployee $employee): array
    {
        $employee->loadMissing([
            'candidate.jobPosting', 'candidate.assignedRecruiter', 'candidate.offer', 'candidate.auditLogs',
            'onboarding.documents', 'auditLogs', 'project:id,name,status',
        ]);

        $candidate  = $employee->candidate;
        $onboarding = $employee->onboarding ?: $candidate?->onboarding()->with('documents')->first();
        $offer      = $candidate?->offer;
        $sub        = $onboarding?->submission ?? [];

        return [
            'employee'    => $employee,
            // STEP 7: Assigned Project, resolved through the relationship (no duplicate text).
            // Project Manager is the project's creator; location has no column on projects.
            'assigned_project' => $employee->project ? [
                'id'              => $employee->project->id,
                'name'            => $employee->project->name,
                'status'          => $employee->project->status,
                'project_manager' => optional($employee->project->creator)->name,
                'location'        => null,
            ] : null,
            'recruitment' => [
                'reference'    => $candidate ? 'CAND-'.str_pad((string) $candidate->id, 4, '0', STR_PAD_LEFT) : null,
                'applied_job'  => optional($candidate?->jobPosting)->title,
                'source'       => $candidate?->source,
                'stage'        => $candidate?->stage,
                'recruiter'    => optional($candidate?->assignedRecruiter)->name,
                'nationality'  => $sub['personal_details']['nationality'] ?? null,
            ],
            'offer' => $offer ? [
                'reference'        => 'OFF-'.str_pad((string) $offer->id, 4, '0', STR_PAD_LEFT),
                'status'           => $offer->status,
                'offered_ctc'      => $offer->offered_ctc,
                'joining_date'     => optional($offer->joining_date)->toDateString(),
                'probation_period' => $offer->probation_period,
                'notice_period'    => $offer->notice_period,
                'access_token'     => $offer->access_token,
                'accepted_at'      => optional($offer->accepted_at)->toIso8601String(),
            ] : null,
            'submission' => [
                'personal'   => $sub['personal_details'] ?? [],
                'address'    => $sub['address'] ?? [],
                'emergency'  => $sub['emergency_contact'] ?? [],
                'bank'       => $sub['bank_details'] ?? [],
                'education'  => $sub['education'] ?? [],
                'experience' => $sub['experience'] ?? [],
            ],
            'onboarding_id' => $onboarding?->id,
            'documents'     => $onboarding
                ? $onboarding->documents->map(fn ($d) => [
                    'id' => $d->id, 'type' => $d->type, 'original_name' => $d->original_name,
                    'status' => $d->status ?? 'Pending',
                ])->all()
                : [],
            'timeline' => $this->timeline($employee, $candidate),
        ];
    }

    /** Merge candidate (recruitment) + employee (post-joining) audit trails. */
    private function timeline(HrEmployee $employee, $candidate): array
    {
        $logs = collect();
        if ($candidate) {
            $logs = $logs->concat($candidate->auditLogs);
        }
        $logs = $logs->concat($employee->auditLogs);

        return $logs->sortBy(fn ($l) => optional($l->created_at)->timestamp)->values()->map(fn ($l) => [
            'action'     => $l->action,
            'actor_name' => $l->actor_name,
            'actor_role' => $l->actor_role,
            'comment'    => $l->comment,
            'metadata'   => $l->metadata,
            'created_at' => optional($l->created_at)->toIso8601String(),
        ])->all();
    }

    public function destroy(HrEmployee $employee): void
    {
        $employee->delete();

        Log::channel('hr')->info('Employee deleted', ['employee_id' => $employee->id, 'tenant_id' => $employee->tenant_id]);
    }

    public function stats(int $tenantId): array
    {
        return [
            'total'    => HrEmployee::where('tenant_id', $tenantId)->count(),
            'active'   => HrEmployee::where('tenant_id', $tenantId)->where('status', 'Active')->count(),
            'on_leave' => HrEmployee::where('tenant_id', $tenantId)->where('status', 'On Leave')->count(),
            'inactive' => HrEmployee::where('tenant_id', $tenantId)->where('status', 'Inactive')->count(),
            'by_dept'  => HrEmployee::where('tenant_id', $tenantId)->select('department')
                ->selectRaw('count(*) as count')
                ->groupBy('department')->get(),
        ];
    }
}
