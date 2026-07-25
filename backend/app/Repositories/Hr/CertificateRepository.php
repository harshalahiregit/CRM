<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeTraining;
use App\Models\Hr\HrTrainingAssessment;
use App\Models\Hr\HrTrainingAttendance;
use App\Models\Hr\HrTrainingCertificate;
use App\Models\Hr\HrTrainingQuiz;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Read queries for Certificates + Completion (L&D Phase 6). Tenant-scoped; no writes. */
class CertificateRepository
{
    private const EAGER = [
        'assignment:id,employee_id,training_program_id,training_session_id,status,completion_percentage',
        'assignment.employee:id,name,employee_code,department,designation',
        'assignment.program:id,program_name,program_code',
        'assignment.session:id,title,trainer_name,start_at',
    ];

    /* ── Certificates ─────────────────────────────────────── */
    public function certificates(int $tenantId, array $f): Collection
    {
        return HrTrainingCertificate::where('tenant_id', $tenantId)
            ->with(self::EAGER)
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['employee_id']), fn ($q) => $q->whereHas('assignment', fn ($a) => $a->where('employee_id', $f['employee_id'])))
            ->orderByDesc('id')->get();
    }

    public function findCertificate(int $id, int $tenantId): ?HrTrainingCertificate
    {
        return HrTrainingCertificate::where('tenant_id', $tenantId)->with([...self::EAGER, 'auditLogs'])->find($id);
    }

    public function certificateForAssignment(int $employeeTrainingId, int $tenantId): ?HrTrainingCertificate
    {
        return HrTrainingCertificate::where('tenant_id', $tenantId)->where('employee_training_id', $employeeTrainingId)->first();
    }

    public function nextSequence(int $tenantId, int $year): int
    {
        return HrTrainingCertificate::where('tenant_id', $tenantId)
            ->where('certificate_number', 'like', "CERT-{$year}-%")->count() + 1;
    }

    public function certificateStats(int $tenantId): array
    {
        $today = Carbon::today();
        $soon = $today->copy()->addDays(30);
        $base = fn () => HrTrainingCertificate::where('tenant_id', $tenantId);

        return [
            'issued'        => (int) $base()->where('status', HrTrainingCertificate::ISSUED)->count(),
            'expired'       => (int) $base()->where(fn ($q) => $q->where('status', HrTrainingCertificate::EXPIRED)
                ->orWhere(fn ($w) => $w->where('status', HrTrainingCertificate::ISSUED)->whereNotNull('expiry_date')->whereDate('expiry_date', '<', $today)))->count(),
            'expiring_soon' => (int) $base()->where('status', HrTrainingCertificate::ISSUED)->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $today)->whereDate('expiry_date', '<=', $soon)->count(),
        ];
    }

    /** Assignments eligible for a certificate but without one yet (Completed, no cert). */
    public function pendingCertificateCount(int $tenantId): int
    {
        return HrEmployeeTraining::where('tenant_id', $tenantId)
            ->where('status', HrEmployeeTraining::COMPLETED)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('hr_training_certificates')
                    ->whereColumn('hr_training_certificates.employee_training_id', 'hr_employee_trainings.id');
            })->count();
    }

    /* ── Completion (derived; merged in the service) ──────── */
    public function assignmentsForCompletion(int $tenantId, array $f): Collection
    {
        return HrEmployeeTraining::where('tenant_id', $tenantId)
            ->with(['employee:id,name,employee_code,department,designation', 'program:id,program_name,program_code', 'session:id,title,trainer_name,start_at'])
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['training_program_id']), fn ($q) => $q->where('training_program_id', $f['training_program_id']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->orderByDesc('id')->get();
    }

    /** Latest attendance status keyed by assignment id. */
    public function attendanceMap(int $tenantId): array
    {
        return HrTrainingAttendance::where('tenant_id', $tenantId)->pluck('attendance_status', 'employee_training_id')->all();
    }

    /** Best assessment result (Pass wins) keyed by assignment id. */
    public function assessmentMap(int $tenantId): array
    {
        $map = [];
        foreach (HrTrainingAssessment::where('tenant_id', $tenantId)->get(['employee_training_id', 'result', 'percentage']) as $a) {
            $k = $a->employee_training_id;
            if (! isset($map[$k]) || $a->result === HrTrainingAssessment::PASS) {
                $map[$k] = ['result' => $a->result, 'percentage' => (float) $a->percentage];
            }
        }

        return $map;
    }

    /** Best quiz (passed wins) keyed by assignment id. */
    public function quizMap(int $tenantId): array
    {
        $map = [];
        foreach (HrTrainingQuiz::where('tenant_id', $tenantId)->get(['employee_training_id', 'passed', 'percentage']) as $q) {
            $k = $q->employee_training_id;
            if (! isset($map[$k]) || $q->passed) {
                $map[$k] = ['passed' => (bool) $q->passed, 'percentage' => (float) $q->percentage];
            }
        }

        return $map;
    }

    /** Certificate summary keyed by assignment id. */
    public function certificateMap(int $tenantId): array
    {
        $map = [];
        foreach (HrTrainingCertificate::where('tenant_id', $tenantId)->get(['id', 'employee_training_id', 'certificate_number', 'status', 'expiry_date']) as $c) {
            $map[$c->employee_training_id] = ['id' => $c->id, 'number' => $c->certificate_number, 'status' => $c->status, 'expiry' => optional($c->expiry_date)->toDateString()];
        }

        return $map;
    }
}
