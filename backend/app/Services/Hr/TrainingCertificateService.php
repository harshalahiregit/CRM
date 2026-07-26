<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeTraining;
use App\Models\Hr\HrTrainingAssessment;
use App\Models\Hr\HrTrainingAttendance;
use App\Models\Hr\HrTrainingCertificate;
use App\Models\Hr\HrTrainingQuiz;
use App\Models\User;
use App\Repositories\Hr\CertificateRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Training Certificates (L&D Phase 6). A certificate is generated only once a
 * training assignment is Completed and its requirements pass (attendance present,
 * assessment passed, quiz passed if any). Issued certificates are immutable
 * (only expiry state / file attachment change). Tenant-scoped, audited.
 */
class TrainingCertificateService
{
    public const DOC_DISK = 'hr_documents';

    public function __construct(private CertificateRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->certificates($tenantId, $f)->map(fn ($c) => $this->present($c))->all(),
            'stats' => $this->repo->certificateStats($tenantId) + ['pending' => $this->repo->pendingCertificateCount($tenantId)],
        ];
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId), true);
    }

    public function generate(array $data, int $tenantId, ?User $actor = null): array
    {
        $assignment = $this->assignment((int) ($data['employee_training_id'] ?? 0), $tenantId);
        $this->assertCompletion($assignment, $tenantId);

        if ($this->repo->certificateForAssignment($assignment->id, $tenantId)) {
            throw new BusinessException('A certificate already exists for this training.');
        }

        $issue = Carbon::parse($data['issue_date'] ?? now()->toDateString());
        $expiry = $this->resolveExpiry($assignment, $data, $issue);

        $year = (int) $issue->year;
        $number = sprintf('CERT-%d-%05d', $year, $this->repo->nextSequence($tenantId, $year));

        $cert = HrTrainingCertificate::create([
            'tenant_id' => $tenantId, 'employee_training_id' => $assignment->id,
            'certificate_number' => $number, 'issue_date' => $issue->toDateString(),
            'expiry_date' => $expiry, 'status' => HrTrainingCertificate::ISSUED,
            'created_by' => $actor?->id, 'updated_by' => $actor?->id,
        ]);
        $assignment->recordAudit('Completion Recorded', $actor, null, ['program' => $assignment->program?->program_name]);
        $cert->recordAudit('Certificate Generated', $actor, null, ['number' => $number]);
        $cert->recordAudit('Certificate Issued', $actor, null, ['employee' => $assignment->employee?->name]);
        $this->log('Certificate generated', $tenantId, $cert->id);

        return $this->show($cert->id, $tenantId);
    }

    public function uploadFile(int $id, string $path, int $tenantId, ?User $actor = null): array
    {
        $cert = $this->find($id, $tenantId);
        $cert->update(['certificate_file' => $path, 'updated_by' => $actor?->id]);
        $cert->recordAudit('Certificate Updated', $actor, 'File attached');

        return $this->show($id, $tenantId);
    }

    public function expire(int $id, int $tenantId, ?User $actor = null): array
    {
        $cert = $this->find($id, $tenantId);
        if ($cert->status === HrTrainingCertificate::EXPIRED) {
            throw new BusinessException('This certificate is already expired.');
        }
        $cert->update(['status' => HrTrainingCertificate::EXPIRED, 'updated_by' => $actor?->id]);
        $cert->recordAudit('Certificate Expired', $actor);

        return $this->show($id, $tenantId);
    }

    public function recordDownload(int $id, int $tenantId, ?User $actor = null): HrTrainingCertificate
    {
        $cert = $this->find($id, $tenantId);
        $cert->recordAudit('Certificate Downloaded', $actor);

        return $cert;
    }

    /* ── Completion gating ────────────────────────────────── */

    private function assertCompletion(HrEmployeeTraining $assignment, int $tenantId): void
    {
        if ($assignment->status !== HrEmployeeTraining::COMPLETED) {
            throw new BusinessException('The training must be completed before a certificate can be generated.');
        }
        $att = HrTrainingAttendance::where('tenant_id', $tenantId)->where('employee_training_id', $assignment->id)->first();
        if (! $att || $att->attendance_status !== HrTrainingAttendance::PRESENT) {
            throw new BusinessException('Attendance must be marked Present to certify this training.');
        }
        $assessments = HrTrainingAssessment::where('tenant_id', $tenantId)->where('employee_training_id', $assignment->id)->get();
        if ($assessments->isNotEmpty() && ! $assessments->contains(fn ($a) => $a->result === HrTrainingAssessment::PASS)) {
            throw new BusinessException('The assessment must be passed before certifying this training.');
        }
        $quizzes = HrTrainingQuiz::where('tenant_id', $tenantId)->where('employee_training_id', $assignment->id)->get();
        if ($quizzes->isNotEmpty() && ! $quizzes->contains(fn ($q) => (bool) $q->passed)) {
            throw new BusinessException('The quiz must be passed before certifying this training.');
        }
    }

    private function resolveExpiry(HrEmployeeTraining $assignment, array $data, Carbon $issue): ?string
    {
        if (! empty($data['expiry_date'])) {
            $expiry = Carbon::parse($data['expiry_date']);
            if ($expiry->lt($issue)) {
                throw new BusinessException('Expiry date cannot be before the issue date.');
            }

            return $expiry->toDateString();
        }
        // Fall back to the program's validity window when set.
        $validity = (int) ($assignment->program?->validity_days ?? 0);

        return $validity > 0 ? $issue->copy()->addDays($validity)->toDateString() : null;
    }

    private function assignment(int $id, int $tenantId): HrEmployeeTraining
    {
        $assignment = HrEmployeeTraining::where('tenant_id', $tenantId)->with(['employee', 'program'])->find($id);
        if (! $assignment) {
            throw new BusinessException('Assignment not found for certification.', 404);
        }

        return $assignment;
    }

    private function present(HrTrainingCertificate $c, bool $full = false): array
    {
        $asg = $c->assignment;
        $expired = $c->status === HrTrainingCertificate::EXPIRED
            || ($c->expiry_date && $c->expiry_date->isPast());
        $out = [
            'id' => $c->id, 'employee_training_id' => $c->employee_training_id,
            'certificate_number' => $c->certificate_number,
            'employee_name' => $asg?->employee?->name, 'employee_code' => $asg?->employee?->employee_code,
            'department' => $asg?->employee?->department,
            'program' => $asg?->program?->program_name, 'program_code' => $asg?->program?->program_code,
            'session_title' => $asg?->session?->title,
            'issue_date' => optional($c->issue_date)->toDateString(),
            'expiry_date' => optional($c->expiry_date)->toDateString(),
            'status' => $expired ? HrTrainingCertificate::EXPIRED : $c->status,
            'has_file' => ! empty($c->certificate_file),
        ];
        if ($full) {
            $out['timeline'] = $c->relationLoaded('auditLogs')
                ? $c->auditLogs->sortBy('id')->values()->map(fn ($l) => ['action' => $l->action, 'actor_name' => $l->actor_name, 'created_at' => optional($l->created_at)->toIso8601String()])->all()
                : [];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrTrainingCertificate
    {
        $cert = $this->repo->findCertificate($id, $tenantId);
        if (! $cert) {
            throw new BusinessException('Certificate not found', 404);
        }

        return $cert;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
