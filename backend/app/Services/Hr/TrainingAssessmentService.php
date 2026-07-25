<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeTraining;
use App\Models\Hr\HrTrainingAssessment;
use App\Models\User;
use App\Repositories\Hr\TrainingRecordRepository;
use Illuminate\Support\Facades\Log;

/**
 * Training Assessment (L&D Phase 5). A marked assessment on a training assignment,
 * only after attendance is recorded. Percentage and Pass/Fail are auto-computed
 * from marks (obtained ≤ total, non-negative). Tenant-scoped, audited.
 */
class TrainingAssessmentService
{
    public function __construct(private TrainingRecordRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->assessments($tenantId, $f)->map(fn ($a) => $this->present($a))->all(),
            'stats' => $this->repo->assessmentStats($tenantId),
        ];
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId), true);
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $assignment = $this->assignment((int) ($data['employee_training_id'] ?? 0), $tenantId);
        if (! $this->repo->attendanceForAssignment($assignment->id, $tenantId)) {
            throw new BusinessException('Record attendance before adding an assessment.');
        }
        [$total, $obtained, $passing, $pct, $result] = $this->compute($data);

        $assessment = HrTrainingAssessment::create([
            'tenant_id' => $tenantId, 'employee_training_id' => $assignment->id,
            'assessment_name' => trim($data['assessment_name'] ?? 'Assessment'),
            'total_marks' => $total, 'obtained_marks' => $obtained, 'passing_marks' => $passing,
            'percentage' => $pct, 'result' => $result, 'remarks' => $data['remarks'] ?? null,
            'created_by' => $actor?->id, 'updated_by' => $actor?->id,
        ]);
        $assessment->recordAudit('Assessment Added', $actor, null, ['result' => $result, 'percentage' => $pct]);
        $this->log('Assessment added', $tenantId, $assessment->id);

        return $this->show($assessment->id, $tenantId);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $assessment = $this->find($id, $tenantId);
        $merged = [
            'total_marks' => $data['total_marks'] ?? $assessment->total_marks,
            'obtained_marks' => $data['obtained_marks'] ?? $assessment->obtained_marks,
            'passing_marks' => $data['passing_marks'] ?? $assessment->passing_marks,
        ];
        [$total, $obtained, $passing, $pct, $result] = $this->compute($merged);

        $assessment->update([
            'assessment_name' => array_key_exists('assessment_name', $data) ? trim($data['assessment_name']) : $assessment->assessment_name,
            'total_marks' => $total, 'obtained_marks' => $obtained, 'passing_marks' => $passing,
            'percentage' => $pct, 'result' => $result,
            'remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $assessment->remarks,
            'updated_by' => $actor?->id,
        ]);
        $assessment->recordAudit('Assessment Updated', $actor, null, ['result' => $result, 'percentage' => $pct]);

        return $this->show($id, $tenantId);
    }

    /* ── Computation + helpers ────────────────────────────── */

    private function compute(array $d): array
    {
        $total = (float) ($d['total_marks'] ?? 0);
        $obtained = (float) ($d['obtained_marks'] ?? 0);
        $passing = (float) ($d['passing_marks'] ?? 0);
        if ($total <= 0) {
            throw new BusinessException('Total marks must be greater than zero.');
        }
        if ($obtained < 0 || $passing < 0) {
            throw new BusinessException('Marks cannot be negative.');
        }
        if ($obtained > $total) {
            throw new BusinessException('Obtained marks cannot exceed total marks.');
        }
        if ($passing > $total) {
            throw new BusinessException('Passing marks cannot exceed total marks.');
        }
        $pct = round($obtained / $total * 100, 2);
        $result = $obtained >= $passing ? HrTrainingAssessment::PASS : HrTrainingAssessment::FAIL;

        return [$total, $obtained, $passing, $pct, $result];
    }

    private function assignment(int $id, int $tenantId): HrEmployeeTraining
    {
        $assignment = HrEmployeeTraining::where('tenant_id', $tenantId)->find($id);
        if (! $assignment) {
            throw new BusinessException('Assessment can only be added for an assigned employee.');
        }

        return $assignment;
    }

    private function present(HrTrainingAssessment $a, bool $full = false): array
    {
        $asg = $a->assignment;
        $out = [
            'id' => $a->id, 'employee_training_id' => $a->employee_training_id,
            'employee_name' => $asg?->employee?->name, 'employee_code' => $asg?->employee?->employee_code,
            'department' => $asg?->employee?->department,
            'program' => $asg?->program?->program_name, 'session_title' => $asg?->session?->title,
            'assessment_name' => $a->assessment_name,
            'total_marks' => (float) $a->total_marks, 'obtained_marks' => (float) $a->obtained_marks,
            'passing_marks' => (float) $a->passing_marks, 'percentage' => (float) $a->percentage,
            'result' => $a->result, 'remarks' => $a->remarks,
        ];
        if ($full) {
            $out['timeline'] = $a->relationLoaded('auditLogs')
                ? $a->auditLogs->sortBy('id')->values()->map(fn ($l) => ['action' => $l->action, 'actor_name' => $l->actor_name, 'created_at' => optional($l->created_at)->toIso8601String()])->all()
                : [];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrTrainingAssessment
    {
        $assessment = $this->repo->findAssessment($id, $tenantId);
        if (! $assessment) {
            throw new BusinessException('Assessment not found', 404);
        }

        return $assessment;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
