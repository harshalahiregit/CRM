<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployeeTraining;
use App\Repositories\Hr\CertificateRepository;

/**
 * Training Completion (L&D Phase 6) — a read-only derived view over assignments,
 * combining attendance / assessment / quiz / certificate into an overall
 * completion state. No new table, no writes. Tenant-scoped.
 */
class TrainingCompletionService
{
    public function __construct(private CertificateRepository $repo)
    {
    }

    /**
     * @param  \App\Models\User|null  $actor  Whose completion records may be
     *                                        returned. Both callers now supply
     *                                        one — the Training report path
     *                                        (Phase 6) and the completion
     *                                        screen's own controller (Phase 7).
     *
     * `stats` below is counted from $rows, which is the scoped set, so the four
     * tiles narrow with the list rather than reporting the tenant's totals
     * beside somebody's department.
     */
    public function list(int $tenantId, array $f, ?\App\Models\User $actor = null): array
    {
        $attendance = $this->repo->attendanceMap($tenantId);
        $assessment = $this->repo->assessmentMap($tenantId);
        $quiz = $this->repo->quizMap($tenantId);
        $cert = $this->repo->certificateMap($tenantId);

        $rows = $this->repo->assignmentsForCompletion($tenantId, $f, $actor)
            ->map(fn ($a) => $this->present($a, $attendance, $assessment, $quiz, $cert))->all();

        $stats = [
            'completed'   => count(array_filter($rows, fn ($r) => $r['status'] === 'Completed' || $r['status'] === 'Certified')),
            'in_progress' => count(array_filter($rows, fn ($r) => $r['status'] === 'In Progress')),
            'failed'      => count(array_filter($rows, fn ($r) => $r['status'] === 'Failed')),
            'certified'   => count(array_filter($rows, fn ($r) => $r['certified'])),
        ];

        return ['data' => $rows, 'stats' => $stats];
    }

    /** Compact completion payload for one employee (Employee Profile Training tab). */
    public function forEmployee(int $employeeId, int $tenantId, ?\App\Models\User $actor = null): array
    {
        return $this->list($tenantId, ['employee_id' => $employeeId], $actor)['data'];
    }

    private function present(HrEmployeeTraining $a, array $att, array $asm, array $qz, array $crt): array
    {
        $attendance = $att[$a->id] ?? null;
        $assessment = $asm[$a->id] ?? null;
        $quiz = $qz[$a->id] ?? null;
        $certificate = $crt[$a->id] ?? null;

        $assessmentPass = $assessment ? $assessment['result'] === 'Pass' : null;
        $quizPass = $quiz ? $quiz['passed'] : null;

        // Overall status: Certified > Failed > Completed > In Progress.
        if ($certificate) {
            $status = 'Certified';
        } elseif ($assessmentPass === false || $quizPass === false) {
            $status = 'Failed';
        } elseif ($a->status === HrEmployeeTraining::COMPLETED) {
            $status = 'Completed';
        } else {
            $status = 'In Progress';
        }

        return [
            'employee_training_id' => $a->id,
            'employee_id' => $a->employee_id, 'employee_name' => $a->employee?->name, 'employee_code' => $a->employee?->employee_code,
            'department' => $a->employee?->department,
            'program' => $a->program?->program_name, 'program_code' => $a->program?->program_code,
            'session_title' => $a->session?->title,
            'assignment_status' => $a->status,
            'completion_percentage' => (int) $a->completion_percentage,
            'attendance' => $attendance,
            'assessment_result' => $assessment['result'] ?? null,
            'assessment_pct' => $assessment['percentage'] ?? null,
            'quiz_passed' => $quiz['passed'] ?? null,
            'quiz_pct' => $quiz['percentage'] ?? null,
            'certified' => (bool) $certificate,
            'certificate_id' => $certificate['id'] ?? null,
            'certificate_number' => $certificate['number'] ?? null,
            'status' => $status,
        ];
    }
}
