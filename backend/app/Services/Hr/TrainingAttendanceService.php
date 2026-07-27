<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeTraining;
use App\Models\Hr\HrTrainingAttendance;
use App\Models\Hr\HrTrainingSession;
use App\Models\User;
use App\Repositories\Hr\TrainingRecordRepository;
use Illuminate\Support\Facades\Log;

/**
 * Training Attendance (L&D Phase 5) — separate from office attendance / SangoeTrack.
 * One record per assignment; only assigned employees; a completed session's
 * attendance is read-only. Supports single + bulk marking. Tenant-scoped, audited.
 */
class TrainingAttendanceService
{
    private const STATUSES = ['Present', 'Absent'];

    public function __construct(private TrainingRecordRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->attendance($tenantId, $f)->map(fn ($a) => $this->present($a))->all(),
            'stats' => $this->repo->attendanceStats($tenantId, $f),
        ];
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->presentFull($this->find($id, $tenantId));
    }

    /** Assigned roster for a session with each employee's current attendance status. */
    public function roster(int $sessionId, int $tenantId): array
    {
        $session = HrTrainingSession::where('tenant_id', $tenantId)->find($sessionId);
        if (! $session) {
            throw new BusinessException('Session not found', 404);
        }
        $existing = HrTrainingAttendance::where('tenant_id', $tenantId)->where('training_session_id', $sessionId)
            ->get()->keyBy('employee_training_id');

        return [
            'session' => ['id' => $session->id, 'title' => $session->title, 'status' => $session->status, 'trainer_name' => $session->trainer_name],
            'roster' => $this->repo->roster($sessionId, $tenantId)->map(function ($a) use ($existing) {
                $att = $existing->get($a->id);

                return [
                    'employee_training_id' => $a->id, 'employee_id' => $a->employee_id,
                    'employee_name' => $a->employee?->name, 'employee_code' => $a->employee?->employee_code,
                    'department' => $a->employee?->department,
                    'attendance_id' => $att?->id, 'attendance_status' => $att?->attendance_status, 'remarks' => $att?->remarks,
                ];
            })->all(),
        ];
    }

    /** Mark one, or bulk mark a whole session roster. */
    public function mark(array $data, int $tenantId, ?User $actor = null): array
    {
        // Bulk: { training_session_id, records: [ {employee_training_id, attendance_status, remarks} ] }
        if (! empty($data['records']) && is_array($data['records'])) {
            $marked = 0;
            foreach ($data['records'] as $rec) {
                if (empty($rec['employee_training_id']) || empty($rec['attendance_status'])) {
                    continue;
                }
                $this->markOne($rec, $tenantId, $actor, true);
                $marked++;
            }

            return ['marked' => $marked] + $this->list($tenantId, ['training_session_id' => $data['training_session_id'] ?? null]);
        }

        $record = $this->markOne($data, $tenantId, $actor, false);

        return $this->presentFull($this->find($record->id, $tenantId));
    }

    private function markOne(array $data, int $tenantId, ?User $actor, bool $upsert): HrTrainingAttendance
    {
        $assignment = $this->assignment((int) ($data['employee_training_id'] ?? 0), $tenantId);
        $session = $assignment->session;
        if ($session && $session->status === HrTrainingSession::CANCELLED) {
            throw new BusinessException('Attendance cannot be marked for a cancelled session.');
        }
        $status = $this->status($data['attendance_status'] ?? null);

        $existing = $this->repo->attendanceForAssignment($assignment->id, $tenantId);
        if ($existing) {
            if (! $upsert) {
                throw new BusinessException('Attendance is already recorded for this employee.');
            }
            $this->assertEditable($existing);
            $existing->update(['attendance_status' => $status, 'remarks' => $data['remarks'] ?? $existing->remarks, 'updated_by' => $actor?->id]);
            $existing->recordAudit('Training Attendance Updated', $actor, null, ['status' => $status]);

            return $existing;
        }

        $record = HrTrainingAttendance::create([
            'tenant_id' => $tenantId,
            'training_session_id' => $assignment->training_session_id,
            'employee_training_id' => $assignment->id,
            'employee_id' => $assignment->employee_id,
            'attendance_status' => $status,
            'check_in' => $data['check_in'] ?? null,
            'check_out' => $data['check_out'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'created_by' => $actor?->id, 'updated_by' => $actor?->id,
        ]);
        $record->recordAudit('Training Attendance Marked', $actor, null, ['status' => $status, 'employee' => $assignment->employee?->name]);
        $this->log('Training attendance marked', $tenantId, $record->id);

        return $record;
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $record = $this->find($id, $tenantId);
        $this->assertEditable($record);
        $attrs = ['updated_by' => $actor?->id];
        if (array_key_exists('attendance_status', $data)) {
            $attrs['attendance_status'] = $this->status($data['attendance_status']);
        }
        foreach (['check_in', 'check_out', 'remarks'] as $k) {
            if (array_key_exists($k, $data)) {
                $attrs[$k] = $data[$k] ?: null;
            }
        }
        $record->update($attrs);
        $record->recordAudit('Training Attendance Updated', $actor, null, ['status' => $record->attendance_status]);

        return $this->presentFull($this->find($id, $tenantId));
    }

    /* ── Helpers ──────────────────────────────────────────── */

    private function assertEditable(HrTrainingAttendance $record): void
    {
        $record->loadMissing('session');
        if ($record->session && $record->session->status === HrTrainingSession::COMPLETED) {
            throw new BusinessException('Attendance for a completed session cannot be edited.');
        }
    }

    private function status(?string $s): string
    {
        $s = ucfirst(strtolower(trim((string) $s)));

        return in_array($s, self::STATUSES, true) ? $s : HrTrainingAttendance::PRESENT;
    }

    private function assignment(int $id, int $tenantId): HrEmployeeTraining
    {
        $assignment = HrEmployeeTraining::where('tenant_id', $tenantId)->with(['employee', 'session'])->find($id);
        if (! $assignment) {
            throw new BusinessException('Attendance can only be marked for an assigned employee.');
        }

        return $assignment;
    }

    private function present(HrTrainingAttendance $a): array
    {
        return [
            'id' => $a->id, 'employee_training_id' => $a->employee_training_id,
            'training_session_id' => $a->training_session_id,
            'employee_id' => $a->employee_id, 'employee_name' => $a->employee?->name, 'employee_code' => $a->employee?->employee_code,
            'department' => $a->employee?->department,
            'session_title' => $a->session?->title, 'trainer_name' => $a->session?->trainer_name,
            'program' => $a->assignment?->program?->program_name,
            'attendance_status' => $a->attendance_status,
            'check_in' => optional($a->check_in)->toIso8601String(), 'check_out' => optional($a->check_out)->toIso8601String(),
            'remarks' => $a->remarks,
        ];
    }

    private function presentFull(HrTrainingAttendance $a): array
    {
        return $this->present($a) + [
            'timeline' => $a->relationLoaded('auditLogs')
                ? $a->auditLogs->sortBy('id')->values()->map(fn ($l) => ['action' => $l->action, 'actor_name' => $l->actor_name, 'created_at' => optional($l->created_at)->toIso8601String()])->all()
                : [],
        ];
    }

    private function find(int $id, int $tenantId): HrTrainingAttendance
    {
        $record = $this->repo->findAttendance($id, $tenantId);
        if (! $record) {
            throw new BusinessException('Attendance record not found', 404);
        }

        return $record;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
