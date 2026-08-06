<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeTraining;
use Illuminate\Database\Eloquent\Collection;

/** Read queries for Employee Training Assignments (L&D Phase 4). Tenant-scoped; no writes. */
class EmployeeTrainingRepository
{
    private const EAGER = [
        'employee:id,name,employee_code,department,designation',
        'program:id,program_name,program_code',
        'session:id,title,trainer_name,mode,start_at,end_at,status,provider_id',
        'session.provider:id,name',
    ];

    public function assignments(int $tenantId, array $f): Collection
    {
        return HrEmployeeTraining::where('tenant_id', $tenantId)
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['training_program_id']), fn ($q) => $q->where('training_program_id', $f['training_program_id']))
            ->when(! empty($f['training_session_id']), fn ($q) => $q->where('training_session_id', $f['training_session_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('employee_code', 'like', '%'.$f['search'].'%');
            })))
            ->orderByDesc('id')->get();
    }

    public function find(int $id, int $tenantId): ?HrEmployeeTraining
    {
        return HrEmployeeTraining::where('tenant_id', $tenantId)->with([...self::EAGER, 'auditLogs'])->find($id);
    }

    /**
     * #23 — every assignment this employee already has for one programme.
     *
     * Newest first by default, because assign() only needs the most recent one to
     * chain `previous_training_id`. The history endpoint asks for oldest first so
     * the attempts read in the order they happened.
     */
    public function priorAssignments(int $employeeId, int $programId, int $tenantId, bool $newestFirst = true): Collection
    {
        return HrEmployeeTraining::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('training_program_id', $programId)
            ->with('session:id,title,start_at,end_at')
            ->orderBy('assigned_at', $newestFirst ? 'desc' : 'asc')
            ->orderBy('id', $newestFirst ? 'desc' : 'asc')
            ->get();
    }

    /**
     * #23 — the programmes this employee has repeated, with how many times.
     *
     * Grouped in the database rather than in PHP: an employee with years of
     * training history should not have every row hydrated to count them.
     */
    public function retrainingSummary(int $employeeId, int $tenantId): array
    {
        return HrEmployeeTraining::where('hr_employee_trainings.tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->selectRaw('training_program_id, COUNT(*) as attempts, MAX(attempt_number) as latest_attempt')
            ->groupBy('training_program_id')
            ->having('attempts', '>', 1)
            ->with('program:id,program_name,program_code')
            ->get()
            ->map(fn ($r) => [
                'training_program_id' => $r->training_program_id,
                'program_name'        => $r->program?->program_name,
                'total_attempts'      => (int) $r->attempts,
                'retraining_count'    => (int) $r->attempts - 1,
            ])->all();
    }

    public function forEmployee(int $employeeId, int $tenantId): Collection
    {
        return HrEmployeeTraining::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->with(self::EAGER)
            ->orderByDesc('id')->get();
    }

    public function history(int $tenantId, array $f): Collection
    {
        return HrEmployeeTraining::where('tenant_id', $tenantId)
            ->whereIn('status', [HrEmployeeTraining::COMPLETED, HrEmployeeTraining::CANCELLED])
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->orderByDesc('completed_at')->orderByDesc('id')->get();
    }

    /** Active (seat-occupying) assignments on a session — for the capacity check. */
    public function activeCountForSession(int $sessionId, int $tenantId): int
    {
        return HrEmployeeTraining::where('tenant_id', $tenantId)
            ->where('training_session_id', $sessionId)
            ->whereIn('status', HrEmployeeTraining::ACTIVE)
            ->count();
    }

    /** Does this employee already have a non-cancelled assignment on this session? */
    public function existsForEmployeeSession(int $employeeId, int $sessionId, int $tenantId): bool
    {
        return HrEmployeeTraining::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('training_session_id', $sessionId)
            ->where('status', '!=', HrEmployeeTraining::CANCELLED)
            ->exists();
    }

    public function stats(int $tenantId): array
    {
        $rows = HrEmployeeTraining::where('tenant_id', $tenantId)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
        $total = (int) array_sum($rows);
        $completed = (int) ($rows[HrEmployeeTraining::COMPLETED] ?? 0);

        return [
            'total'          => $total,
            'assigned'       => (int) ($rows[HrEmployeeTraining::ASSIGNED] ?? 0),
            'in_progress'    => (int) ($rows[HrEmployeeTraining::IN_PROGRESS] ?? 0),
            'completed'      => $completed,
            'cancelled'      => (int) ($rows[HrEmployeeTraining::CANCELLED] ?? 0),
            'completion_pct' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
        ];
    }
}
