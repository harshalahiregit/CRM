<?php

namespace App\Repositories\Hr;

use App\Models\User;
use App\Services\Auth\ScopeResolver;
use App\Support\Hr\DataScope;
use App\Support\Sql\SqlDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregation over existing L&D data (Training Reports — Phase 7).
 *
 * Sources: hr_employee_trainings, hr_training_sessions/programs/certificates,
 * hr_training_attendance/assessments/quizzes, hr_employees. Nothing is written.
 * Tenant-scoped first; aggregates grouped in SQL to avoid N+1. No office /
 * SangoeTrack attendance is touched.
 */
class TrainingReportRepository
{
    /**
     * The one place this class talks to the scope resolver.
     *
     * Training is the happiest of the six: base() is a genuine choke point that
     * seven of the report methods already go through, so the scope reaches the
     * employee rows, the department rollup, the programme and trainer rollups,
     * attendance, assessments and certificates in one call. The dashboard and
     * the trends read their own tables and are scoped individually.
     *
     * BRANCH is excluded as in Phase 1.
     */
    private function scoped($query, ?User $actor, string $column)
    {
        return app(ScopeResolver::class)->applyToQuery($query, $actor, $column, [
            DataScope::OWN, DataScope::DEPARTMENT, DataScope::TEAM,
        ]);
    }

    /** Base assignment query joined to employee / program / session with shared filters. */
    private function base(int $tenantId, array $f, ?User $actor = null)
    {
        $q = DB::table('hr_employee_trainings as et')
            ->join('hr_employees as e', 'et.employee_id', '=', 'e.id')
            ->join('hr_training_programs as p', 'et.training_program_id', '=', 'p.id')
            ->join('hr_training_sessions as s', 'et.training_session_id', '=', 's.id')
            ->where('et.tenant_id', $tenantId);

        // Scope before filters — a filter narrows within the scope, never past it.
        $q = $this->scoped($q, $actor, 'et.employee_id');

        if (! empty($f['year']))          { $q->whereRaw($this->yearExpr('s.start_at').' = ?', [(int) $f['year']]); }
        if (! empty($f['month']))         { $q->whereRaw($this->monthExpr('s.start_at').' = ?', [(int) $f['month']]); }
        if (! empty($f['employee_id']))   { $q->where('et.employee_id', $f['employee_id']); }
        if (! empty($f['department']) && $f['department'] !== 'All')   { $q->where('e.department', $f['department']); }
        if (! empty($f['designation']) && $f['designation'] !== 'All') { $q->where('e.designation', $f['designation']); }
        if (! empty($f['training_program_id'])) { $q->where('et.training_program_id', $f['training_program_id']); }
        if (! empty($f['provider_id']))   { $q->where('s.provider_id', $f['provider_id']); }
        if (! empty($f['category_id']))   { $q->where('p.category_id', $f['category_id']); }
        if (! empty($f['training_type_id'])) { $q->where('p.training_type_id', $f['training_type_id']); }
        if (! empty($f['trainer']) && $f['trainer'] !== 'All') { $q->where('s.trainer_name', $f['trainer']); }
        if (! empty($f['status']) && $f['status'] !== 'All')   { $q->where('et.status', $f['status']); }

        return $q;
    }

    /* ── Dashboard (tenant-scoped counts) ─────────────────── */
    /**
     * Dashboard tiles.
     *
     * The catalogue tiles — programmes, sessions, upcoming sessions — are NOT
     * scoped: a training programme is a course the company runs, not a fact
     * about a person, and hiding half the catalogue from a department head
     * would be wrong as well as useless.
     *
     * Everything about who took what IS scoped. Certificates and assessments
     * carry no employee_id of their own, so they reach it through
     * hr_employee_trainings, which does.
     */
    public function dashboard(int $tenantId, ?User $actor = null): array
    {
        $count = fn ($t) => (int) DB::table($t)->where('tenant_id', $tenantId)->count();

        $assign = $this->scoped(DB::table('hr_employee_trainings')->where('tenant_id', $tenantId), $actor, 'employee_id')
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed")->first();

        $assess = $this->scoped(
            DB::table('hr_training_assessments as a')
                ->join('hr_employee_trainings as et', 'a.employee_training_id', '=', 'et.id')
                ->where('a.tenant_id', $tenantId),
            $actor, 'et.employee_id'
        )->selectRaw("COUNT(*) as total, SUM(CASE WHEN a.result='Pass' THEN 1 ELSE 0 END) as passed, AVG(a.percentage) as avg_pct")->first();

        $certificates = (int) $this->scoped(
            DB::table('hr_training_certificates as c')
                ->join('hr_employee_trainings as et', 'c.employee_training_id', '=', 'et.id')
                ->where('c.tenant_id', $tenantId),
            $actor, 'et.employee_id'
        )->count();

        $upcoming = (int) DB::table('hr_training_sessions')->where('tenant_id', $tenantId)
            ->where('status', 'Scheduled')->where('start_at', '>=', now())->count();

        return [
            'total_programs'   => $count('hr_training_programs'),
            'total_sessions'   => $count('hr_training_sessions'),
            'assignments'      => (int) ($assign->total ?? 0),
            'completed'        => (int) ($assign->completed ?? 0),
            'certificates'     => $certificates,
            'pass_pct'         => ($assess->total ?? 0) > 0 ? round($assess->passed / $assess->total * 100, 1) : 0.0,
            'average_score'    => round((float) ($assess->avg_pct ?? 0), 1),
            'upcoming_sessions'=> $upcoming,
        ];
    }

    /* ── Report rows ──────────────────────────────────────── */
    public function employees(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->base($tenantId, $f, $actor)
                ->leftJoin('hr_training_attendance as at', 'at.employee_training_id', '=', 'et.id')
                ->leftJoin('hr_training_certificates as c', 'c.employee_training_id', '=', 'et.id')
                ->selectRaw("e.name, e.employee_code, e.department, e.designation, p.program_name,
                    s.title as session_title, s.trainer_name, et.status, et.completion_percentage,
                    at.attendance_status, c.certificate_number")
                ->orderByDesc('et.id')->get()
        );
    }

    public function departments(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->base($tenantId, $f, $actor)
                ->leftJoin('hr_training_certificates as c', 'c.employee_training_id', '=', 'et.id')
                ->leftJoin('hr_training_assessments as a', 'a.employee_training_id', '=', 'et.id')
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(DISTINCT et.id) as assignments,
                    SUM(CASE WHEN et.status='Completed' THEN 1 ELSE 0 END) as completed,
                    COUNT(DISTINCT c.id) as certified, AVG(a.percentage) as avg_score")->get()
        );
    }

    public function programs(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->base($tenantId, $f, $actor)
                ->leftJoin('hr_training_assessments as a', 'a.employee_training_id', '=', 'et.id')
                ->groupBy('p.id', 'p.program_name', 'p.program_code')
                ->selectRaw("p.program_name, p.program_code, COUNT(DISTINCT et.id) as assignments,
                    COUNT(DISTINCT et.training_session_id) as sessions,
                    SUM(CASE WHEN et.status='Completed' THEN 1 ELSE 0 END) as completed,
                    AVG(a.percentage) as avg_score,
                    SUM(CASE WHEN a.result='Pass' THEN 1 ELSE 0 END) as passed, COUNT(a.id) as assessed")->get()
        );
    }

    public function trainers(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->base($tenantId, $f, $actor)
                ->groupBy('s.trainer_name')
                ->selectRaw("COALESCE(s.trainer_name,'—') as trainer, COUNT(DISTINCT et.training_session_id) as sessions,
                    COUNT(DISTINCT et.id) as assignments,
                    SUM(CASE WHEN et.status='Completed' THEN 1 ELSE 0 END) as completed")->get()
        );
    }

    public function attendance(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->base($tenantId, $f, $actor)
                ->join('hr_training_attendance as at', 'at.employee_training_id', '=', 'et.id')
                ->selectRaw('e.name, e.employee_code, e.department, p.program_name, s.title as session_title, s.trainer_name, at.attendance_status')
                ->orderByDesc('at.id')->get()
        );
    }

    public function assessments(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->base($tenantId, $f, $actor)
                ->join('hr_training_assessments as a', 'a.employee_training_id', '=', 'et.id')
                ->selectRaw('e.name, e.employee_code, e.department, p.program_name, a.assessment_name, a.total_marks, a.obtained_marks, a.percentage, a.result')
                ->orderByDesc('a.id')->get()
        );
    }

    public function certificates(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->base($tenantId, $f, $actor)
                ->join('hr_training_certificates as c', 'c.employee_training_id', '=', 'et.id')
                ->selectRaw('e.name, e.employee_code, e.department, p.program_name, c.certificate_number, c.issue_date, c.expiry_date, c.status')
                ->orderByDesc('c.id')->get()
        );
    }

    /* ── Trends (rows fetched, aggregated in PHP) ─────────── */
    public function trendAssignments(int $tenantId, int $year, ?User $actor = null): Collection
    {
        return collect(
            $this->scoped(
                DB::table('hr_employee_trainings as et')->join('hr_training_sessions as s', 'et.training_session_id', '=', 's.id')
                    ->where('et.tenant_id', $tenantId),
                $actor, 'et.employee_id'
            )->whereRaw($this->yearExpr('s.start_at').' = ?', [$year])
                ->get(['s.start_at', 'et.status'])
        );
    }

    /**
     * How many sessions ran each month — a schedule, not a roster.
     *
     * Left unscoped on purpose, matching the catalogue tiles on the dashboard.
     * A session happened whether or not this actor's people attended it, and
     * the row carries no employee.
     */
    public function trendSessions(int $tenantId, int $year): Collection
    {
        return collect(
            DB::table('hr_training_sessions')->where('tenant_id', $tenantId)
                ->whereRaw($this->yearExpr('start_at').' = ?', [$year])
                ->get(['start_at', 'end_at'])
        );
    }

    public function trendAssessments(int $tenantId, int $year, ?User $actor = null): Collection
    {
        return collect(
            $this->scoped(
                DB::table('hr_training_assessments as a')->join('hr_employee_trainings as et', 'a.employee_training_id', '=', 'et.id')
                    ->join('hr_training_sessions as s', 'et.training_session_id', '=', 's.id')
                    ->where('a.tenant_id', $tenantId),
                $actor, 'et.employee_id'
            )->whereRaw($this->yearExpr('s.start_at').' = ?', [$year])
                ->get(['s.start_at', 'a.result'])
        );
    }

    /** Certificates reach their employee through the assignment they belong to. */
    public function trendCertificates(int $tenantId, int $year, ?User $actor = null): Collection
    {
        return collect(
            $this->scoped(
                DB::table('hr_training_certificates as c')
                    ->join('hr_employee_trainings as et', 'c.employee_training_id', '=', 'et.id')
                    ->where('c.tenant_id', $tenantId),
                $actor, 'et.employee_id'
            )->whereRaw($this->yearExpr('c.issue_date').' = ?', [$year])->get(['c.issue_date'])
        );
    }

    /**
     * The dropdown contents.
     *
     * Only the three employee-derived lists are scoped. Programmes, providers,
     * categories, types, trainers, the session years and the status vocabulary
     * are the training catalogue — the same for everyone, and filtering them
     * down would hide courses rather than protect anybody.
     */
    public function filterOptions(int $tenantId, ?User $actor = null): array
    {
        $employees = $this->scoped(DB::table('hr_employees')->where('tenant_id', $tenantId), $actor, 'id');

        return [
            'years' => DB::table('hr_training_sessions')->where('tenant_id', $tenantId)
                ->selectRaw('DISTINCT '.$this->yearExpr('start_at').' as y')->orderByDesc('y')->pluck('y')->filter()->values()->all(),
            'departments' => (clone $employees)->whereNotNull('department')->where('department', '!=', '')
                ->distinct()->orderBy('department')->pluck('department')->all(),
            'designations' => (clone $employees)->whereNotNull('designation')->where('designation', '!=', '')
                ->distinct()->orderBy('designation')->pluck('designation')->all(),
            'employees' => (clone $employees)->orderBy('name')->get(['id', 'name', 'employee_code'])->all(),
            'programs' => DB::table('hr_training_programs')->where('tenant_id', $tenantId)->orderBy('program_name')->get(['id', 'program_name as name', 'program_code as code'])->all(),
            'providers' => DB::table('hr_training_providers')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])->all(),
            'categories' => DB::table('hr_training_categories')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])->all(),
            'training_types' => DB::table('hr_training_types')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])->all(),
            'trainers' => DB::table('hr_training_sessions')->where('tenant_id', $tenantId)->whereNotNull('trainer_name')->distinct()->orderBy('trainer_name')->pluck('trainer_name')->all(),
            'statuses' => ['Assigned', 'In Progress', 'Completed', 'Cancelled'],
        ];
    }

    private function yearExpr(string $col): string
    {
        return SqlDate::year($col);
    }

    private function monthExpr(string $col): string
    {
        return SqlDate::month($col);
    }
}
