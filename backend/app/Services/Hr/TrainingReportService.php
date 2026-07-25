<?php

namespace App\Services\Hr;

use App\Repositories\Hr\TrainingReportRepository;
use Illuminate\Support\Carbon;

/**
 * Training Reports & Analytics (L&D Phase 7) — read-only. Shapes aggregate L&D
 * data into report payloads; never writes or recomputes stored values.
 */
class TrainingReportService
{
    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(
        private TrainingReportRepository $repo,
        private TrainingCompletionService $completion,
    ) {
    }

    public function dashboard(int $tenantId): array
    {
        return $this->repo->dashboard($tenantId);
    }

    public function employees(int $tenantId, array $f): array
    {
        return $this->repo->employees($tenantId, $f)->map(fn ($r) => [
            'employee_name' => $r->name, 'employee_code' => $r->employee_code, 'department' => $r->department, 'designation' => $r->designation,
            'program' => $r->program_name, 'session' => $r->session_title, 'trainer' => $r->trainer_name,
            'attendance' => $r->attendance_status, 'status' => $r->status,
            'completion' => (int) $r->completion_percentage, 'certificate' => $r->certificate_number,
        ])->all();
    }

    public function departments(int $tenantId, array $f): array
    {
        return $this->repo->departments($tenantId, $f)->map(function ($r) {
            $a = (int) $r->assignments;

            return [
                'department' => $r->department, 'assignments' => $a, 'completed' => (int) $r->completed,
                'certified' => (int) $r->certified, 'avg_score' => round((float) ($r->avg_score ?? 0), 1),
                'completion_pct' => $a > 0 ? round((int) $r->completed / $a * 100, 1) : 0.0,
            ];
        })->all();
    }

    public function programs(int $tenantId, array $f): array
    {
        return $this->repo->programs($tenantId, $f)->map(function ($r) {
            $assessed = (int) $r->assessed;

            return [
                'program' => $r->program_name, 'code' => $r->program_code,
                'sessions' => (int) $r->sessions, 'assignments' => (int) $r->assignments, 'completed' => (int) $r->completed,
                'avg_score' => round((float) ($r->avg_score ?? 0), 1),
                'pass_pct' => $assessed > 0 ? round((int) $r->passed / $assessed * 100, 1) : 0.0,
            ];
        })->all();
    }

    public function trainers(int $tenantId, array $f): array
    {
        return $this->repo->trainers($tenantId, $f)->map(fn ($r) => [
            'trainer' => $r->trainer, 'sessions' => (int) $r->sessions,
            'assignments' => (int) $r->assignments, 'completed' => (int) $r->completed,
        ])->all();
    }

    public function attendance(int $tenantId, array $f): array
    {
        return $this->repo->attendance($tenantId, $f)->map(fn ($r) => [
            'employee_name' => $r->name, 'employee_code' => $r->employee_code, 'department' => $r->department,
            'program' => $r->program_name, 'session' => $r->session_title, 'trainer' => $r->trainer_name,
            'attendance' => $r->attendance_status,
        ])->all();
    }

    public function assessments(int $tenantId, array $f): array
    {
        return $this->repo->assessments($tenantId, $f)->map(fn ($r) => [
            'employee_name' => $r->name, 'employee_code' => $r->employee_code, 'department' => $r->department,
            'program' => $r->program_name, 'assessment' => $r->assessment_name,
            'total' => (float) $r->total_marks, 'obtained' => (float) $r->obtained_marks,
            'percentage' => (float) $r->percentage, 'result' => $r->result,
        ])->all();
    }

    public function certificates(int $tenantId, array $f): array
    {
        return $this->repo->certificates($tenantId, $f)->map(fn ($r) => [
            'employee_name' => $r->name, 'employee_code' => $r->employee_code, 'department' => $r->department,
            'program' => $r->program_name, 'certificate_number' => $r->certificate_number,
            'issue_date' => $r->issue_date, 'expiry_date' => $r->expiry_date, 'status' => $r->status,
        ])->all();
    }

    public function completion(int $tenantId, array $f): array
    {
        return array_map(fn ($r) => [
            'employee_name' => $r['employee_name'], 'employee_code' => $r['employee_code'], 'department' => $r['department'],
            'program' => $r['program'], 'attendance' => $r['attendance'], 'assessment' => $r['assessment_result'],
            'quiz' => $r['quiz_passed'] === null ? '—' : ($r['quiz_passed'] ? 'Passed' : 'Failed'),
            'completion' => $r['completion_percentage'], 'certified' => $r['certified'] ? 'Yes' : 'No', 'status' => $r['status'],
        ], $this->completion->list($tenantId, $f)['data']);
    }

    public function trends(int $tenantId, array $f): array
    {
        $year = (int) ($f['year'] ?? now()->year);
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['month' => self::MONTHS[$m], 'trainings' => 0, 'completed' => 0, 'sessions' => 0,
                'hours' => 0.0, 'certificates' => 0, '_pass' => 0, '_assessed' => 0];
        }
        foreach ($this->repo->trendAssignments($tenantId, $year) as $r) {
            $m = $this->m($r->start_at); if (! $m) continue;
            $months[$m]['trainings']++;
            if ($r->status === 'Completed') { $months[$m]['completed']++; }
        }
        foreach ($this->repo->trendSessions($tenantId, $year) as $r) {
            $m = $this->m($r->start_at); if (! $m) continue;
            $months[$m]['sessions']++;
            if ($r->start_at && $r->end_at) {
                $months[$m]['hours'] += max(0, Carbon::parse($r->end_at)->floatDiffInHours(Carbon::parse($r->start_at)));
            }
        }
        foreach ($this->repo->trendAssessments($tenantId, $year) as $r) {
            $m = $this->m($r->start_at); if (! $m) continue;
            $months[$m]['_assessed']++;
            if ($r->result === 'Pass') { $months[$m]['_pass']++; }
        }
        foreach ($this->repo->trendCertificates($tenantId, $year) as $r) {
            $m = $this->m($r->issue_date); if (! $m) continue;
            $months[$m]['certificates']++;
        }

        return array_values(array_map(function ($row) {
            $row['hours'] = round($row['hours'], 1);
            $row['completion_pct'] = $row['trainings'] > 0 ? round($row['completed'] / $row['trainings'] * 100, 1) : 0.0;
            $row['pass_pct'] = $row['_assessed'] > 0 ? round($row['_pass'] / $row['_assessed'] * 100, 1) : 0.0;
            unset($row['_pass'], $row['_assessed']);

            return $row;
        }, $months));
    }

    public function filterOptions(int $tenantId): array
    {
        return $this->repo->filterOptions($tenantId);
    }

    /* ── Export rows (CSV / PDF share the shaped data) ────── */
    public function exportRows(string $report, int $tenantId, array $f): array
    {
        return match ($report) {
            'departments' => ['title' => 'Training Department Report',
                'headers' => ['Department', 'Assignments', 'Completed', 'Certified', 'Avg Score', 'Completion %'],
                'rows' => array_map(fn ($d) => [$d['department'], $d['assignments'], $d['completed'], $d['certified'], $d['avg_score'], $d['completion_pct']], $this->departments($tenantId, $f))],
            'programs' => ['title' => 'Training Program Report',
                'headers' => ['Program', 'Code', 'Sessions', 'Assignments', 'Completed', 'Avg Score', 'Pass %'],
                'rows' => array_map(fn ($p) => [$p['program'], $p['code'], $p['sessions'], $p['assignments'], $p['completed'], $p['avg_score'], $p['pass_pct']], $this->programs($tenantId, $f))],
            'trainers' => ['title' => 'Trainer Report',
                'headers' => ['Trainer', 'Sessions', 'Assignments', 'Completed'],
                'rows' => array_map(fn ($t) => [$t['trainer'], $t['sessions'], $t['assignments'], $t['completed']], $this->trainers($tenantId, $f))],
            'attendance' => ['title' => 'Training Attendance Report',
                'headers' => ['Employee', 'Code', 'Department', 'Program', 'Session', 'Trainer', 'Attendance'],
                'rows' => array_map(fn ($a) => [$a['employee_name'], $a['employee_code'], $a['department'], $a['program'], $a['session'], $a['trainer'], $a['attendance']], $this->attendance($tenantId, $f))],
            'assessments' => ['title' => 'Training Assessment Report',
                'headers' => ['Employee', 'Department', 'Program', 'Assessment', 'Total', 'Obtained', 'Percentage', 'Result'],
                'rows' => array_map(fn ($a) => [$a['employee_name'], $a['department'], $a['program'], $a['assessment'], $a['total'], $a['obtained'], $a['percentage'], $a['result']], $this->assessments($tenantId, $f))],
            'certificates' => ['title' => 'Training Certificate Report',
                'headers' => ['Employee', 'Department', 'Program', 'Certificate No', 'Issue Date', 'Expiry Date', 'Status'],
                'rows' => array_map(fn ($c) => [$c['employee_name'], $c['department'], $c['program'], $c['certificate_number'], $c['issue_date'], $c['expiry_date'] ?? '—', $c['status']], $this->certificates($tenantId, $f))],
            'completion' => ['title' => 'Training Completion Report',
                'headers' => ['Employee', 'Department', 'Program', 'Attendance', 'Assessment', 'Quiz', 'Completion %', 'Certified', 'Status'],
                'rows' => array_map(fn ($c) => [$c['employee_name'], $c['department'], $c['program'], $c['attendance'] ?? '—', $c['assessment'] ?? '—', $c['quiz'], $c['completion'], $c['certified'], $c['status']], $this->completion($tenantId, $f))],
            'trends' => ['title' => 'Training Monthly Trends',
                'headers' => ['Month', 'Trainings', 'Completed', 'Sessions', 'Hours', 'Certificates', 'Completion %', 'Pass %'],
                'rows' => array_map(fn ($t) => [$t['month'], $t['trainings'], $t['completed'], $t['sessions'], $t['hours'], $t['certificates'], $t['completion_pct'], $t['pass_pct']], $this->trends($tenantId, $f))],
            default => ['title' => 'Employee Training Report',
                'headers' => ['Employee', 'Department', 'Program', 'Session', 'Trainer', 'Attendance', 'Status', 'Completion %', 'Certificate'],
                'rows' => array_map(fn ($e) => [$e['employee_name'], $e['department'], $e['program'], $e['session'], $e['trainer'], $e['attendance'] ?? '—', $e['status'], $e['completion'], $e['certificate'] ?? '—'], $this->employees($tenantId, $f))],
        };
    }

    private function m($date): ?int
    {
        $m = (int) substr((string) $date, 5, 2);

        return ($m >= 1 && $m <= 12) ? $m : null;
    }
}
