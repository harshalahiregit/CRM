<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrOnboarding;
use App\Models\User;
use App\Repositories\Hr\EmployeeRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeService
{
    public function __construct(
        private EmployeeRepository $employeeRepository,
        private EmployeeProbationService $probation,
    ) {
    }

    /** @param  User|null  $actor  Whose view this is; null is unscoped, as before. */
    public function list(int $tenantId, array $filters, ?User $actor = null): LengthAwarePaginator
    {
        return $this->employeeRepository->filtered($tenantId, $filters, $actor);
    }

    /**
     * Whether this employee may report to that one.
     *
     * Two rules, and both matter because OrgChartService groups the whole tenant
     * by reporting_manager_id and walks down from the roots. A self-reference or
     * a loop does not produce a wrong chart, it produces a chart that never
     * finishes building — and AdvanceTierService walks the same edge to decide
     * who approves an advance.
     *
     * Only reachable on UPDATE. At create there is no id yet, so neither rule can
     * be broken, which is why create() does not call this.
     *
     * Existence and tenant are already settled by the request's Rule::exists, so
     * this deliberately re-checks neither.
     */
    private function assertManagerIsUsable(HrEmployee $employee, $managerId): void
    {
        if ($managerId === null || $managerId === '') {
            return;   // clearing the manager is always allowed
        }

        $managerId = (int) $managerId;

        if ($managerId === (int) $employee->id) {
            throw new BusinessException('An employee cannot report to themselves.', 422);
        }

        // Walk up from the proposed manager. If we arrive back at this employee,
        // the edge would close a loop. Bounded by the number of employees so a
        // loop that already exists in the data cannot hang the request.
        $seen    = [];
        $current = $managerId;
        $limit   = HrEmployee::where('tenant_id', $employee->tenant_id)->count() + 1;

        for ($i = 0; $i < $limit && $current !== null; $i++) {
            if ((int) $current === (int) $employee->id) {
                throw new BusinessException(
                    'That would make the reporting line circular — '
                    .'the person you picked already reports to this employee.',
                    422
                );
            }

            if (isset($seen[$current])) {
                break;   // a pre-existing loop further up; not this edge's fault
            }
            $seen[$current] = true;

            $current = HrEmployee::where('tenant_id', $employee->tenant_id)
                ->whereKey($current)
                ->value('reporting_manager_id');
        }
    }

    /**
     * Review comment #36 — "Probation must be set while adding any employee in
     * system. Without adding process should not complete."
     *
     * The employee and their probation are created in ONE transaction. If the
     * probation cannot be assigned, the employee is not created either — that is
     * what "the process should not complete" means, and a half-created employee
     * with no probation is precisely the state the comment is about.
     *
     * `probation_policy_id` is required by the request layer. The one exception is
     * `skip_probation`, which exists for genuinely exempt hires (a re-hire already
     * confirmed, a consultant) and is recorded on the audit trail so the exemption
     * is visible rather than silent.
     *
     * The SangoeTrack bulk importer writes HrEmployee directly and is deliberately
     * NOT routed through here: blocking an import of hundreds of existing staff on
     * probation policy would be wrong, and those employees are not new hires.
     */
    public function create(array $data, int $tenantId, ?User $actor = null): HrEmployee
    {
        $data['tenant_id'] = $tenantId;

        // Was `count() + 1`, which reuses a code as soon as anyone is deleted:
        // five employees, delete the third, and the next create asks for -005
        // while -005 already exists. HrEmployee::nextEmployeeCode takes the
        // highest sequence actually issued instead, and is shared with the
        // SangoeTrack importer so the two cannot drift apart.
        $empCode = HrEmployee::nextEmployeeCode($tenantId);

        $skipProbation    = (bool) ($data['skip_probation'] ?? false);
        $probationOptional = (bool) ($data['probation_optional'] ?? false);
        $probationReason  = $data['probation_skip_reason'] ?? null;
        $probationInput   = [
            'probation_policy_id'  => $data['probation_policy_id'] ?? null,
            'probation_start_date' => $data['probation_start_date'] ?? null,
            'probation_end_date'   => $data['probation_end_date'] ?? null,
        ];

        // Not columns on hr_employees — strip before create() so a future
        // $fillable change cannot start persisting them by accident.
        unset($data['skip_probation'], $data['probation_skip_reason'], $data['probation_optional'],
              $data['probation_policy_id'], $data['probation_start_date']);

        $employee = DB::transaction(function () use ($data, $empCode, $tenantId, $skipProbation, $probationOptional, $probationInput, $probationReason, $actor) {
            $employee = HrEmployee::create([...$data, 'employee_code' => $empCode]);

            if ($skipProbation) {
                $employee->recordAudit('Probation Skipped', $actor, $probationReason,
                    ['reason' => $probationReason ?: 'No reason given']);

                return $employee;
            }

            try {
                // Reuses the probation module wholesale — dates, duration from the
                // type, review cycle and audit all stay defined in one place.
                $this->probation->assign([
                    'employee_id' => $employee->id,
                ] + array_filter($probationInput, fn ($v) => $v !== null), $tenantId, $actor);
            } catch (BusinessException $e) {
                // `probation_optional` is passed only by the onboarding conversion.
                // A tenant with no probation policy configured would otherwise be
                // unable to complete ANY onboarding — a regression in a working
                // flow, caused by a rule aimed at the manual add-employee screen.
                // The exemption is recorded, never silent.
                if (! $probationOptional) {
                    throw $e;
                }
                $employee->recordAudit('Probation Not Assigned', $actor, $e->getMessage(),
                    ['reason' => $e->getMessage()]);
            }

            return $employee;
        });

        // Milestone that closes the recruitment → employee lifecycle timeline.
        $employee->recordAudit('Employee Created', $actor, null, ['employee_code' => $employee->employee_code]);

        Log::channel('hr')->info('Employee created', ['employee_id' => $employee->id, 'tenant_id' => $tenantId]);

        return $employee;
    }

    public function update(HrEmployee $employee, array $data, ?User $actor = null): HrEmployee
    {
        if (array_key_exists('reporting_manager_id', $data)) {
            $this->assertManagerIsUsable($employee, $data['reporting_manager_id']);
        }

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
