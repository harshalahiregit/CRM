<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeTraining;
use App\Models\Hr\HrTrainingAssessment;
use App\Models\Hr\HrTrainingAttendance;
use App\Models\Hr\HrTrainingQuiz;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read queries for Training records — Attendance / Assessment / Quiz (L&D Phase 5).
 * Tenant-scoped; no writes. Aggregates are grouped in SQL to avoid N+1.
 */
class TrainingRecordRepository
{
    private const ASSIGN_EAGER = [
        'assignment:id,employee_id,training_program_id,training_session_id,status',
        'assignment.employee:id,name,employee_code,department,designation',
        'assignment.program:id,program_name,program_code',
        'assignment.session:id,title,trainer_name,start_at',
    ];

    /* ── Attendance ───────────────────────────────────────── */
    public function attendance(int $tenantId, array $f): Collection
    {
        return HrTrainingAttendance::where('tenant_id', $tenantId)
            ->with([
                'session:id,title,trainer_name,start_at',
                'employee:id,name,employee_code,department,designation',
                'assignment:id,training_program_id', 'assignment.program:id,program_name,program_code',
            ])
            ->when(! empty($f['training_session_id']), fn ($q) => $q->where('training_session_id', $f['training_session_id']))
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['attendance_status']) && $f['attendance_status'] !== 'All', fn ($q) => $q->where('attendance_status', $f['attendance_status']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->orderByDesc('id')->get();
    }

    public function findAttendance(int $id, int $tenantId): ?HrTrainingAttendance
    {
        return HrTrainingAttendance::where('tenant_id', $tenantId)
            ->with(['session:id,title,trainer_name,start_at,status', 'employee:id,name,employee_code,department', 'assignment:id,training_program_id,status', 'assignment.program:id,program_name', 'auditLogs'])
            ->find($id);
    }

    public function attendanceForAssignment(int $employeeTrainingId, int $tenantId): ?HrTrainingAttendance
    {
        return HrTrainingAttendance::where('tenant_id', $tenantId)->where('employee_training_id', $employeeTrainingId)->first();
    }

    /** Assigned employees for a session + their attendance (for the roster / bulk marking). */
    public function roster(int $sessionId, int $tenantId): Collection
    {
        return HrEmployeeTraining::where('tenant_id', $tenantId)
            ->where('training_session_id', $sessionId)
            ->whereIn('status', HrEmployeeTraining::ACTIVE + [HrEmployeeTraining::COMPLETED])
            ->with(['employee:id,name,employee_code,department,designation'])
            ->get();
    }

    public function attendanceStats(int $tenantId, array $f): array
    {
        // Assigned universe (optionally scoped to a session) vs marked present/absent.
        $assignBase = HrEmployeeTraining::where('tenant_id', $tenantId)->whereIn('status', HrEmployeeTraining::ACTIVE + [HrEmployeeTraining::COMPLETED]);
        if (! empty($f['training_session_id'])) {
            $assignBase->where('training_session_id', $f['training_session_id']);
        }
        $assigned = (clone $assignBase)->count();

        $attBase = HrTrainingAttendance::where('tenant_id', $tenantId);
        if (! empty($f['training_session_id'])) {
            $attBase->where('training_session_id', $f['training_session_id']);
        }
        $present = (int) (clone $attBase)->where('attendance_status', HrTrainingAttendance::PRESENT)->count();
        $absent = (int) (clone $attBase)->where('attendance_status', HrTrainingAttendance::ABSENT)->count();
        $marked = $present + $absent;

        return [
            'assigned'   => $assigned,
            'present'    => $present,
            'absent'     => $absent,
            'pending'    => max(0, $assigned - $marked),
            'attendance_pct' => $marked > 0 ? round($present / $marked * 100, 1) : 0.0,
        ];
    }

    /* ── Assessment ───────────────────────────────────────── */
    public function assessments(int $tenantId, array $f): Collection
    {
        return HrTrainingAssessment::where('tenant_id', $tenantId)
            ->with(self::ASSIGN_EAGER)
            ->when(! empty($f['employee_training_id']), fn ($q) => $q->where('employee_training_id', $f['employee_training_id']))
            ->when(! empty($f['result']) && $f['result'] !== 'All', fn ($q) => $q->where('result', $f['result']))
            ->when(! empty($f['employee_id']), fn ($q) => $q->whereHas('assignment', fn ($a) => $a->where('employee_id', $f['employee_id'])))
            ->orderByDesc('id')->get();
    }

    public function findAssessment(int $id, int $tenantId): ?HrTrainingAssessment
    {
        return HrTrainingAssessment::where('tenant_id', $tenantId)->with([...self::ASSIGN_EAGER, 'auditLogs'])->find($id);
    }

    public function assessmentStats(int $tenantId): array
    {
        $rows = HrTrainingAssessment::where('tenant_id', $tenantId)
            ->selectRaw("SUM(CASE WHEN result='Pass' THEN 1 ELSE 0 END) as passed,
                SUM(CASE WHEN result='Fail' THEN 1 ELSE 0 END) as failed,
                COUNT(*) as total, AVG(percentage) as avg_pct")->first();

        return [
            'passed'  => (int) ($rows->passed ?? 0),
            'failed'  => (int) ($rows->failed ?? 0),
            'total'   => (int) ($rows->total ?? 0),
            'avg_pct' => round((float) ($rows->avg_pct ?? 0), 1),
        ];
    }

    /* ── Quiz ─────────────────────────────────────────────── */
    public function quizzes(int $tenantId, array $f): Collection
    {
        return HrTrainingQuiz::where('tenant_id', $tenantId)
            ->with(self::ASSIGN_EAGER)
            ->when(! empty($f['employee_training_id']), fn ($q) => $q->where('employee_training_id', $f['employee_training_id']))
            ->when(isset($f['passed']) && $f['passed'] !== '' && $f['passed'] !== 'All', fn ($q) => $q->where('passed', $f['passed'] === 'Passed' || $f['passed'] === '1' || $f['passed'] === true))
            ->when(! empty($f['employee_id']), fn ($q) => $q->whereHas('assignment', fn ($a) => $a->where('employee_id', $f['employee_id'])))
            ->orderByDesc('id')->get();
    }

    public function findQuiz(int $id, int $tenantId): ?HrTrainingQuiz
    {
        return HrTrainingQuiz::where('tenant_id', $tenantId)->with([...self::ASSIGN_EAGER, 'auditLogs'])->find($id);
    }

    public function quizStats(int $tenantId): array
    {
        $rows = HrTrainingQuiz::where('tenant_id', $tenantId)
            ->selectRaw('SUM(CASE WHEN passed=1 THEN 1 ELSE 0 END) as passed, COUNT(*) as total, AVG(percentage) as avg_pct')->first();
        $total = (int) ($rows->total ?? 0);
        $passed = (int) ($rows->passed ?? 0);

        return [
            'completed' => $total,
            'passed'    => $passed,
            'failed'    => $total - $passed,
            'avg_pct'   => round((float) ($rows->avg_pct ?? 0), 1),
        ];
    }
}
