<?php

namespace App\Services\Hr;

use App\Repositories\Hr\ProbationReportRepository;

/**
 * Probation Reports & Analytics (Probation Phase 6) — read-only. Shapes aggregate
 * Probation data into report payloads; never writes or recomputes stored values.
 */
class ProbationReportService
{
    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(private ProbationReportRepository $repo)
    {
    }

    public function dashboard(int $tenantId): array
    {
        return $this->repo->dashboard($tenantId);
    }

    public function employees(int $tenantId, array $f): array
    {
        return $this->repo->employees($tenantId, $f)->map(fn ($r) => [
            'employee_name' => $r->name, 'employee_code' => $r->employee_code, 'department' => $r->department, 'designation' => $r->designation,
            'policy' => $r->policy, 'type' => $r->ptype,
            'start_date' => $r->probation_start_date, 'end_date' => $r->probation_end_date,
            'status' => $r->current_status, 'extension_count' => (int) $r->extension_count,
            'latest_review' => $r->review_rating ? "{$r->review_rating}/5 · {$r->review_reco}" : '—',
            'confirmation_status' => $r->confirmation_status ?: '—',
        ])->all();
    }

    public function departments(int $tenantId, array $f): array
    {
        return $this->repo->departments($tenantId, $f)->map(fn ($r) => [
            'department' => $r->department, 'employees' => (int) $r->employees,
            'active' => (int) $r->active, 'extended' => (int) $r->extended,
            'confirmed' => (int) $r->confirmed, 'rejected' => (int) $r->cancelled,
            'avg_duration' => round((float) ($r->avg_duration ?? 0), 1),
        ])->all();
    }

    public function policies(int $tenantId, array $f): array
    {
        return $this->repo->policies($tenantId, $f)->map(function ($r) {
            $emp = (int) $r->employees;

            return [
                'policy' => $r->policy, 'employees' => $emp,
                'confirmed_pct' => $emp > 0 ? round((int) $r->confirmed / $emp * 100, 1) : 0.0,
                'extended_pct' => $emp > 0 ? round((int) $r->extended / $emp * 100, 1) : 0.0,
                'avg_duration' => round((float) ($r->avg_duration ?? 0), 1),
            ];
        })->all();
    }

    public function reviews(int $tenantId): array
    {
        $summary = $this->repo->reviewSummary($tenantId);
        $dist = [];
        foreach (['Continue', 'Extend', 'Confirm', 'Fail'] as $rec) {
            $dist[$rec] = 0;
        }
        foreach ($this->repo->reviewRecommendations($tenantId) as $row) {
            if ($row->recommendation) {
                $dist[$row->recommendation] = (int) $row->c;
            }
        }

        return $summary + ['recommendations' => $dist];
    }

    public function extensions(int $tenantId, array $f): array
    {
        return $this->repo->extensions($tenantId, $f)->map(fn ($r) => [
            'department' => $r->department, 'requested' => (int) $r->requested,
            'approved' => (int) $r->approved, 'rejected' => (int) $r->rejected,
            'avg_days' => round((float) ($r->avg_days ?? 0), 1),
        ])->all();
    }

    public function confirmations(int $tenantId, array $f): array
    {
        return $this->repo->confirmations($tenantId, $f)->map(fn ($r) => [
            'employee_name' => $r->name, 'employee_code' => $r->employee_code, 'department' => $r->department,
            'policy' => $r->policy, 'recommendation' => $r->recommendation ?: '—', 'decision' => $r->decision ?: '—',
            'confirmation_date' => $r->confirmation_date, 'effective_date' => $r->effective_date, 'status' => $r->status,
        ])->all();
    }

    public function trends(int $tenantId, array $f): array
    {
        $year = (int) ($f['year'] ?? now()->year);
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['month' => self::MONTHS[$m], 'probations' => 0, 'reviews' => 0, 'extensions' => 0, 'confirmations' => 0, 'rejections' => 0];
        }
        foreach ($this->repo->trendProbations($tenantId, $year) as $r) {
            if ($m = $this->m($r->probation_start_date)) { $months[$m]['probations']++; }
        }
        foreach ($this->repo->trendReviews($tenantId, $year) as $r) {
            if ($m = $this->m($r->review_date)) { $months[$m]['reviews']++; }
        }
        foreach ($this->repo->trendExtensions($tenantId, $year) as $r) {
            if ($m = $this->m($r->created_at)) { $months[$m]['extensions']++; }
        }
        foreach ($this->repo->trendConfirmations($tenantId, $year) as $r) {
            $m = $this->m($r->created_at);
            if (! $m) { continue; }
            if ($r->status === 'Confirmed') { $months[$m]['confirmations']++; }
            if ($r->status === 'Rejected') { $months[$m]['rejections']++; }
        }

        return array_values($months);
    }

    public function filterOptions(int $tenantId): array
    {
        return $this->repo->filterOptions($tenantId);
    }

    /* ── Export rows (CSV / PDF share the shaped data) ────── */
    public function exportRows(string $report, int $tenantId, array $f): array
    {
        return match ($report) {
            'departments' => ['title' => 'Probation Department Report',
                'headers' => ['Department', 'Employees', 'Active', 'Extended', 'Confirmed', 'Rejected', 'Avg Duration (days)'],
                'rows' => array_map(fn ($d) => [$d['department'], $d['employees'], $d['active'], $d['extended'], $d['confirmed'], $d['rejected'], $d['avg_duration']], $this->departments($tenantId, $f))],
            'policies' => ['title' => 'Probation Policy Report',
                'headers' => ['Policy', 'Employees', 'Confirmed %', 'Extended %', 'Avg Duration (days)'],
                'rows' => array_map(fn ($p) => [$p['policy'], $p['employees'], $p['confirmed_pct'], $p['extended_pct'], $p['avg_duration']], $this->policies($tenantId, $f))],
            'extensions' => ['title' => 'Probation Extension Report',
                'headers' => ['Department', 'Requested', 'Approved', 'Rejected', 'Avg Extension Days'],
                'rows' => array_map(fn ($x) => [$x['department'], $x['requested'], $x['approved'], $x['rejected'], $x['avg_days']], $this->extensions($tenantId, $f))],
            'confirmations' => ['title' => 'Probation Confirmation Report',
                'headers' => ['Employee', 'Code', 'Department', 'Policy', 'Recommendation', 'Decision', 'Confirmation Date', 'Effective Date', 'Status'],
                'rows' => array_map(fn ($c) => [$c['employee_name'], $c['employee_code'], $c['department'], $c['policy'], $c['recommendation'], $c['decision'], $c['confirmation_date'] ?? '—', $c['effective_date'] ?? '—', $c['status']], $this->confirmations($tenantId, $f))],
            'reviews' => (function () use ($tenantId) {
                $r = $this->reviews($tenantId);
                $rows = [['Completed Reviews', $r['completed']], ['Pending Reviews', $r['pending']], ['Average Rating', $r['avg_rating']]];
                foreach ($r['recommendations'] as $k => $v) { $rows[] = ["Recommendation: {$k}", $v]; }

                return ['title' => 'Probation Review Report', 'headers' => ['Metric', 'Value'], 'rows' => $rows];
            })(),
            'trends' => ['title' => 'Probation Monthly Trends',
                'headers' => ['Month', 'New Probations', 'Reviews', 'Extensions', 'Confirmations', 'Rejections'],
                'rows' => array_map(fn ($t) => [$t['month'], $t['probations'], $t['reviews'], $t['extensions'], $t['confirmations'], $t['rejections']], $this->trends($tenantId, $f))],
            default => ['title' => 'Employee Probation Report',
                'headers' => ['Employee', 'Department', 'Policy', 'Type', 'Start Date', 'End Date', 'Status', 'Extensions', 'Latest Review', 'Confirmation'],
                'rows' => array_map(fn ($e) => [$e['employee_name'], $e['department'], $e['policy'], $e['type'], $e['start_date'], $e['end_date'], $e['status'], $e['extension_count'], $e['latest_review'], $e['confirmation_status']], $this->employees($tenantId, $f))],
        };
    }

    private function m($date): ?int
    {
        $m = (int) substr((string) $date, 5, 2);

        return ($m >= 1 && $m <= 12) ? $m : null;
    }
}
