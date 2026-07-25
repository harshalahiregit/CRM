<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeTraining;
use App\Models\Hr\HrTrainingQuiz;
use App\Models\User;
use App\Repositories\Hr\TrainingRecordRepository;
use Illuminate\Support\Facades\Log;

/**
 * Training Quiz (L&D Phase 5). A scored quiz on a training assignment. Percentage
 * is auto-computed; passed is derived from the program's passing percentage
 * (falling back to 50%). Marks: obtained ≤ total, non-negative. Tenant-scoped,
 * audited.
 */
class TrainingQuizService
{
    private const DEFAULT_PASS_PCT = 50;

    public function __construct(private TrainingRecordRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->quizzes($tenantId, $f)->map(fn ($q) => $this->present($q))->all(),
            'stats' => $this->repo->quizStats($tenantId),
        ];
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId), true);
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $assignment = $this->assignment((int) ($data['employee_training_id'] ?? 0), $tenantId);
        [$total, $obtained, $pct] = $this->compute($data);
        $passed = $pct >= $this->threshold($assignment);

        $quiz = HrTrainingQuiz::create([
            'tenant_id' => $tenantId, 'employee_training_id' => $assignment->id,
            'quiz_name' => trim($data['quiz_name'] ?? 'Quiz'),
            'total_marks' => $total, 'obtained_marks' => $obtained, 'percentage' => $pct, 'passed' => $passed,
            'remarks' => $data['remarks'] ?? null, 'created_by' => $actor?->id, 'updated_by' => $actor?->id,
        ]);
        $quiz->recordAudit('Quiz Added', $actor, null, ['passed' => $passed, 'percentage' => $pct]);
        $this->log('Quiz added', $tenantId, $quiz->id);

        return $this->show($quiz->id, $tenantId);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $quiz = $this->find($id, $tenantId);
        [$total, $obtained, $pct] = $this->compute([
            'total_marks' => $data['total_marks'] ?? $quiz->total_marks,
            'obtained_marks' => $data['obtained_marks'] ?? $quiz->obtained_marks,
        ]);
        $passed = $pct >= $this->threshold($quiz->assignment);

        $quiz->update([
            'quiz_name' => array_key_exists('quiz_name', $data) ? trim($data['quiz_name']) : $quiz->quiz_name,
            'total_marks' => $total, 'obtained_marks' => $obtained, 'percentage' => $pct, 'passed' => $passed,
            'remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $quiz->remarks,
            'updated_by' => $actor?->id,
        ]);
        $quiz->recordAudit('Quiz Updated', $actor, null, ['passed' => $passed, 'percentage' => $pct]);

        return $this->show($id, $tenantId);
    }

    /* ── Computation + helpers ────────────────────────────── */

    private function compute(array $d): array
    {
        $total = (float) ($d['total_marks'] ?? 0);
        $obtained = (float) ($d['obtained_marks'] ?? 0);
        if ($total <= 0) {
            throw new BusinessException('Total marks must be greater than zero.');
        }
        if ($obtained < 0) {
            throw new BusinessException('Marks cannot be negative.');
        }
        if ($obtained > $total) {
            throw new BusinessException('Obtained marks cannot exceed total marks.');
        }

        return [$total, $obtained, round($obtained / $total * 100, 2)];
    }

    private function threshold(?HrEmployeeTraining $assignment): float
    {
        $pct = (int) ($assignment?->program?->passing_percentage ?? 0);

        return $pct > 0 ? $pct : self::DEFAULT_PASS_PCT;
    }

    private function assignment(int $id, int $tenantId): HrEmployeeTraining
    {
        $assignment = HrEmployeeTraining::where('tenant_id', $tenantId)->with('program')->find($id);
        if (! $assignment) {
            throw new BusinessException('Quiz can only be added for an assigned employee.');
        }

        return $assignment;
    }

    private function present(HrTrainingQuiz $q, bool $full = false): array
    {
        $asg = $q->assignment;
        $out = [
            'id' => $q->id, 'employee_training_id' => $q->employee_training_id,
            'employee_name' => $asg?->employee?->name, 'employee_code' => $asg?->employee?->employee_code,
            'department' => $asg?->employee?->department,
            'program' => $asg?->program?->program_name, 'session_title' => $asg?->session?->title,
            'quiz_name' => $q->quiz_name,
            'total_marks' => (float) $q->total_marks, 'obtained_marks' => (float) $q->obtained_marks,
            'percentage' => (float) $q->percentage, 'passed' => (bool) $q->passed, 'remarks' => $q->remarks,
        ];
        if ($full) {
            $out['timeline'] = $q->relationLoaded('auditLogs')
                ? $q->auditLogs->sortBy('id')->values()->map(fn ($l) => ['action' => $l->action, 'actor_name' => $l->actor_name, 'created_at' => optional($l->created_at)->toIso8601String()])->all()
                : [];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrTrainingQuiz
    {
        $quiz = $this->repo->findQuiz($id, $tenantId);
        if (! $quiz) {
            throw new BusinessException('Quiz not found', 404);
        }

        return $quiz;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
