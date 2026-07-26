<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeTraining;
use App\Models\Hr\HrTrainingSession;
use App\Models\User;
use App\Repositories\Hr\EmployeeTrainingRepository;
use Illuminate\Support\Facades\Log;

/**
 * Employee Training Assignment (L&D Phase 4). Assigns an employee to an ACTIVE
 * Training Session (Scheduled/Ongoing), enforcing session capacity and blocking
 * duplicate active assignments. Program / Provider / Mode are inherited from the
 * session — nothing is duplicated. Lifecycle: Assigned → In Progress → Completed,
 * Cancelled before completion; Completed / Cancelled are read-only. Tenant-scoped,
 * audited.
 */
class EmployeeTrainingService
{
    public function __construct(private EmployeeTrainingRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->assignments($tenantId, $f)->map(fn ($a) => $this->present($a))->all(),
            'stats' => $this->repo->stats($tenantId),
        ];
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $assignment = $this->find($id, $tenantId);
        $assignment->recordAudit('Training Viewed', $actor);

        return $this->present($assignment, true);
    }

    public function forEmployee(int $employeeId, int $tenantId): array
    {
        return $this->repo->forEmployee($employeeId, $tenantId)->map(fn ($a) => $this->present($a))->all();
    }

    public function history(int $tenantId, array $f): array
    {
        return $this->repo->history($tenantId, $f)->map(fn ($a) => $this->present($a))->all();
    }

    /* ── Assign ───────────────────────────────────────────── */

    public function assign(array $data, int $tenantId, ?User $actor = null): array
    {
        $employee = $this->employee((int) ($data['employee_id'] ?? 0), $tenantId);
        $session = $this->session((int) ($data['training_session_id'] ?? 0), $tenantId);

        if (in_array($session->status, HrTrainingSession::TERMINAL, true)) {
            throw new BusinessException('Employees can only be assigned to active (scheduled or ongoing) sessions.');
        }
        if ($this->repo->existsForEmployeeSession($employee->id, $session->id, $tenantId)) {
            throw new BusinessException('This employee is already assigned to that session.');
        }
        if ($this->repo->activeCountForSession($session->id, $tenantId) >= (int) $session->capacity) {
            throw new BusinessException("Session capacity ({$session->capacity}) is full.");
        }

        $assignment = HrEmployeeTraining::create([
            'tenant_id'           => $tenantId,
            'employee_id'         => $employee->id,
            'training_program_id' => $session->training_program_id, // inherited from the session
            'training_session_id' => $session->id,
            'assigned_by'         => $actor?->id,
            'assigned_at'         => now(),
            'due_date'            => $data['due_date'] ?? optional($session->end_at)->toDateString(),
            'status'              => HrEmployeeTraining::ASSIGNED,
            'remarks'             => $data['remarks'] ?? null,
            'completion_percentage' => 0,
            'created_by'          => $actor?->id,
            'updated_by'          => $actor?->id,
        ]);
        $assignment->recordAudit('Training Assigned', $actor, $data['remarks'] ?? null, ['employee' => $employee->name, 'session' => $session->title]);
        $this->log('Training assigned', $tenantId, $assignment->id);

        return $this->present($this->find($assignment->id, $tenantId), true);
    }

    /* ── Status transitions ───────────────────────────────── */

    public function start(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $assignment = $this->find($id, $tenantId);
        if ($assignment->status !== HrEmployeeTraining::ASSIGNED) {
            throw new BusinessException('Only an assigned training can be started.');
        }
        $pct = $this->pct($data['completion_percentage'] ?? null);
        $assignment->update([
            'status'                => HrEmployeeTraining::IN_PROGRESS,
            'started_at'            => now(),
            'completion_percentage' => $pct ?? $assignment->completion_percentage,
            'updated_by'            => $actor?->id,
        ]);
        $assignment->recordAudit('Training Started', $actor);
        $this->log('Training started', $tenantId, $assignment->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function complete(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $assignment = $this->find($id, $tenantId);
        if (! in_array($assignment->status, HrEmployeeTraining::ACTIVE, true)) {
            throw new BusinessException('Only an assigned or in-progress training can be completed.');
        }
        $assignment->update([
            'status'                => HrEmployeeTraining::COMPLETED,
            'completed_at'          => now(),
            'completion_percentage' => 100,
            'remarks'               => $data['remarks'] ?? $assignment->remarks,
            'updated_by'            => $actor?->id,
        ]);
        $assignment->recordAudit('Training Completed', $actor, $data['remarks'] ?? null);
        $this->log('Training completed', $tenantId, $assignment->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function cancel(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $assignment = $this->find($id, $tenantId);
        if (in_array($assignment->status, HrEmployeeTraining::TERMINAL, true)) {
            throw new BusinessException("A {$assignment->status} training cannot be cancelled.");
        }
        $assignment->update([
            'status'     => HrEmployeeTraining::CANCELLED,
            'remarks'    => $data['remarks'] ?? $assignment->remarks,
            'updated_by' => $actor?->id,
        ]);
        $assignment->recordAudit('Training Cancelled', $actor, $data['remarks'] ?? null);
        $this->log('Training cancelled', $tenantId, $assignment->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    /* ── Helpers ──────────────────────────────────────────── */

    private function pct($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return max(0, min(100, (int) $v));
    }

    private function present(HrEmployeeTraining $a, bool $full = false): array
    {
        $session = $a->session;
        $out = [
            'id' => $a->id,
            'employee_id' => $a->employee_id,
            'employee_name' => $a->employee?->name, 'employee_code' => $a->employee?->employee_code,
            'department' => $a->employee?->department, 'designation' => $a->employee?->designation,
            'training_program_id' => $a->training_program_id, 'program' => $a->program?->program_name, 'program_code' => $a->program?->program_code,
            'training_session_id' => $a->training_session_id,
            'session_title' => $session?->title, 'trainer_name' => $session?->trainer_name,
            'mode' => $session?->mode, 'provider' => $session?->provider?->name,             // inherited
            'session_start' => optional($session?->start_at)->toIso8601String(),
            'session_status' => $session?->status,
            'assigned_at' => optional($a->assigned_at)->toIso8601String(),
            'due_date' => optional($a->due_date)->toDateString(),
            'status' => $a->status,
            'completion_percentage' => (int) $a->completion_percentage,
            'remarks' => $a->remarks,
            'started_at' => optional($a->started_at)->toIso8601String(),
            'completed_at' => optional($a->completed_at)->toIso8601String(),
        ];

        if ($full) {
            $out['timeline'] = $a->relationLoaded('auditLogs')
                ? $a->auditLogs->sortBy('id')->values()->map(fn ($l) => [
                    'action' => $l->action, 'actor_name' => $l->actor_name,
                    'comment' => $l->comment, 'created_at' => optional($l->created_at)->toIso8601String(),
                ])->all()
                : [];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrEmployeeTraining
    {
        $assignment = $this->repo->find($id, $tenantId);
        if (! $assignment) {
            throw new BusinessException('Training assignment not found', 404);
        }

        return $assignment;
    }

    private function employee(int $employeeId, int $tenantId): HrEmployee
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        return $employee;
    }

    private function session(int $sessionId, int $tenantId): HrTrainingSession
    {
        $session = HrTrainingSession::where('tenant_id', $tenantId)->find($sessionId);
        if (! $session) {
            throw new BusinessException('Selected training session is invalid.');
        }

        return $session;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
