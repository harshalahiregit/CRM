<?php

namespace App\Services\Medical;

use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Tpv\TpvWorkerMedical;
use App\Support\Medical\HealthScore;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Medical module's reporting.
 *
 * One service over both registers, because the two worker-medical tables were
 * built to the same column set — the only real differences are which table to
 * read and how a record reaches its vendor, and those are stated once in
 * `shape()` rather than smeared through every aggregate.
 *
 * The numbers answer the questions somebody actually asks about medicals: how
 * many, for whom, how many passed, how many failed and why, and how healthy the
 * workforce is. "Success" here means CLEARED — passing, current and approved by
 * the quality team — because a certificate nobody accepted has not succeeded at
 * anything.
 */
class MedicalReportService
{
    /**
     * @param  'tpv'|'purchase'  $module
     * @param  array{from?:string,to?:string,vendor_id?:int|string|null,project?:string|null,worker_id?:int|string|null}  $filters
     */
    public function build(string $module, int $tenantId, array $filters = []): array
    {
        $shape = $this->shape($module);
        $rows  = $this->rows($module, $tenantId, $filters);

        return [
            'module'      => $module,
            'filters'     => [
                'from'      => $filters['from'] ?? null,
                'to'        => $filters['to'] ?? null,
                'vendor_id' => $filters['vendor_id'] ?? null,
                'project'   => $filters['project'] ?? null,
                'worker_id' => $filters['worker_id'] ?? null,
            ],
            'generated_at' => now()->toDateTimeString(),
            'totals'       => $this->totals($rows),
            'health'       => $this->health($rows),
            'by_vendor'    => $this->byVendor($rows),
            'by_worker'    => $this->byWorker($rows),
            'by_project'   => $this->byProject($rows),
            'by_doctor'    => $this->byDoctor($rows),
            'by_month'     => $this->byMonth($rows),
            'rejections'   => $this->rejections($rows),
            'turnaround'   => $this->turnaround($rows),
            'vendor_label' => $shape['vendor_label'],
        ];
    }

    /* ── Reading ────────────────────────────────────────────────────────── */

    /**
     * Every examination in scope, flattened to the handful of fields the
     * aggregates need. Flattening once keeps each aggregate a plain collection
     * operation instead of eleven near-identical GROUP BY queries.
     */
    private function rows(string $module, int $tenantId, array $filters): Collection
    {
        $shape = $this->shape($module);

        $query = $shape['model']::query()
            ->forTenant($tenantId)
            ->with($shape['with'])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('exam_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('exam_date', '<=', $to))
            ->limit(20000);

        if ($vendorId = ($filters['vendor_id'] ?? null)) {
            $query = $shape['scope_vendor']($query, (int) $vendorId);
        }

        // The brief asked for the report to be filterable by project and by
        // employee as well as by vendor. Both live on the WORKER, not on the
        // examination, so both reach through the worker relation — and both
        // engines keep the same column names, so one closure serves each.
        if ($project = trim((string) ($filters['project'] ?? ''))) {
            $query = $shape['scope_project']($query, $project);
        }

        if ($workerId = ($filters['worker_id'] ?? null)) {
            $query = $shape['scope_worker']($query, (int) $workerId);
        }

        return $query->get()->map(function ($m) use ($shape) {
            [$vendorId, $vendorName] = $shape['vendor_of']($m);

            return [
                'id'          => $m->id,
                'worker_id'   => $shape['worker_key']($m),
                'vendor_id'   => $vendorId,
                'vendor'      => $vendorName ?: 'Unassigned',
                // Named and placed, so the report can answer "how is THIS project
                // doing" and "what is this person's history" — the two questions
                // the brief asked for that a vendor breakdown cannot.
                'worker'      => $shape['worker_name']($m) ?: 'Unknown worker',
                'worker_code' => $m->worker?->worker_code,
                'project'     => $m->worker?->project ?: null,
                'exam_date'   => $m->exam_date ? Carbon::parse($m->exam_date) : null,
                'fitness'     => $m->fitness_status,
                'passing'     => $m->isPassing(),
                'qc'          => $m->qc_status,
                'cleared'     => $m->isCurrentlyValid(),
                'expired'     => $m->isExpired(),
                'valid_until' => $m->valid_until ?? $m->expiry_date,
                'origin'      => $m->origin,
                'external'    => MedicalWorkflow::isExternal($m->origin),
                'score'       => $m->health_score !== null ? (float) $m->health_score : null,
                'reason'      => $m->qc_reason_code,
                'is_reexam'   => (bool) $m->is_reexam,
                'iterations'  => (int) $m->iteration_count,
                'doctor'      => $m->doctor?->name ?: $m->examiner_name,
                'licence'     => $m->doctor_license_no,
                'created_at'  => $m->created_at,
                'qc_at'       => $m->qc_at,
            ];
        });
    }

    /**
     * What differs between the two registers, in one place.
     *
     * @return array{model:class-string, with:array, vendor_label:string, vendor_of:callable, worker_key:callable, scope_vendor:callable}
     */
    private function shape(string $module): array
    {
        if ($module === 'purchase') {
            return [
                'model'        => PurchaseWorkerMedical::class,
                'with'         => ['worker:id,full_name,worker_code,purchase_vendor_id,project', 'worker.vendor:id,company_name', 'doctor:id,name'],
                'vendor_label' => 'Purchase vendor',
                // Purchase keeps the vendor on the medical itself.
                'vendor_of'    => fn ($m) => [$m->purchase_vendor_id, $m->worker?->vendor?->company_name],
                'worker_key'   => fn ($m) => $m->purchase_worker_id,
                'worker_name'  => fn ($m) => $m->worker?->full_name,
                'scope_vendor' => fn ($q, $id) => $q->where('purchase_vendor_id', $id),
                // Project is free text on the worker, so match on it rather than
                // an id — a project the worker was assigned to before the work
                // package existed still has its name recorded.
                'scope_project' => fn ($q, $name) => $q->whereHas('worker', fn ($w) => $w->where('project', 'like', "%{$name}%")),
                'scope_worker'  => fn ($q, $id) => $q->where('purchase_worker_id', $id),
            ];
        }

        return [
            'model'        => TpvWorkerMedical::class,
            'with'         => ['worker:id,name,worker_code,vendor_id,project', 'worker.vendor:id,company_name', 'doctor:id,name'],
            'vendor_label' => 'TPV vendor',
            // TPV reaches its vendor through the worker.
            'vendor_of'    => fn ($m) => [$m->worker?->vendor_id, $m->worker?->vendor?->company_name],
            'worker_key'   => fn ($m) => $m->tpv_worker_id,
            'worker_name'  => fn ($m) => $m->worker?->name,
            'scope_vendor' => fn ($q, $id) => $q->whereHas('worker', fn ($w) => $w->where('vendor_id', $id)),
            'scope_project' => fn ($q, $name) => $q->whereHas('worker', fn ($w) => $w->where('project', 'like', "%{$name}%")),
            'scope_worker'  => fn ($q, $id) => $q->where('tpv_worker_id', $id),
        ];
    }

    /* ── Aggregates ─────────────────────────────────────────────────────── */

    private function totals(Collection $rows): array
    {
        $decided = $rows->whereIn('qc', [MedicalQcStatus::APPROVED, MedicalQcStatus::REJECTED])->count();
        $scored  = $rows->whereNotNull('score');

        return [
            'examinations'    => $rows->count(),
            'workers'         => $rows->pluck('worker_id')->unique()->count(),
            'vendors'         => $rows->pluck('vendor_id')->filter()->unique()->count(),

            // Where the certificates came from.
            'internal'        => $rows->where('external', false)->count(),
            'external'        => $rows->where('external', true)->count(),
            're_examinations' => $rows->where('is_reexam', true)->count(),

            // The quality-check funnel.
            'approved'        => $rows->where('qc', MedicalQcStatus::APPROVED)->count(),
            'pending_review'  => $rows->where('qc', MedicalQcStatus::PENDING)->count(),
            'on_hold'         => $rows->where('qc', MedicalQcStatus::HOLD)->count(),
            'rejected'        => $rows->where('qc', MedicalQcStatus::REJECTED)->count(),

            // The medical outcome itself, which is a different question.
            'fit'             => $rows->where('fitness', 'Fit')->count(),
            'fit_restricted'  => $rows->where('fitness', 'Fit_With_Restrictions')->count(),
            'unfit'           => $rows->where('fitness', 'Unfit')->count(),

            // Successes and failures, as the brief asks: cleared vs not.
            'successes'       => $rows->where('cleared', true)->count(),
            'failures'        => $rows->filter(fn ($r) => $r['qc'] === MedicalQcStatus::REJECTED || ! $r['passing'])->count(),
            // Of the certificates somebody has actually ruled on.
            'decided'         => $decided,
            'success_rate'    => $decided ? round($rows->where('qc', MedicalQcStatus::APPROVED)->count() / $decided * 100, 1) : null,

            // Currency.
            'expired'         => $rows->where('expired', true)->count(),
            'expiring_30d'    => $rows->filter(fn ($r) => $r['valid_until']
                && ! $r['expired']
                && Carbon::parse($r['valid_until'])->lte(now()->addDays(30)))->count(),

            'avg_health_score' => $scored->count() ? round($scored->avg('score'), 1) : null,
            'score_scale'      => HealthScore::MAX,
        ];
    }

    /** The health-rating distribution — the workforce's shape, not one worker's. */
    private function health(Collection $rows): array
    {
        $scored = $rows->whereNotNull('score');

        $bands = ['Excellent' => 0, 'Good' => 0, 'Fair' => 0, 'Poor' => 0];
        foreach ($scored as $r) {
            $band = HealthScore::band($r['score']);
            if ($band !== null) {
                $bands[$band]++;
            }
        }

        return [
            'scored'  => $scored->count(),
            'average' => $scored->count() ? round($scored->avg('score'), 1) : null,
            'best'    => $scored->count() ? round($scored->max('score'), 1) : null,
            'worst'   => $scored->count() ? round($scored->min('score'), 1) : null,
            'bands'   => collect($bands)->map(fn ($count, $band) => [
                'band'    => $band,
                'count'   => $count,
                'percent' => $scored->count() ? round($count / $scored->count() * 100, 1) : 0,
            ])->values()->all(),
        ];
    }

    /** Per-vendor statistics — the table the brief calls "vendor stats". */
    private function byVendor(Collection $rows): array
    {
        return $rows->groupBy('vendor')->map(function (Collection $group, $vendor) {
            $decided = $group->whereIn('qc', [MedicalQcStatus::APPROVED, MedicalQcStatus::REJECTED])->count();
            $scored  = $group->whereNotNull('score');

            return [
                'vendor'         => $vendor,
                'vendor_id'      => $group->first()['vendor_id'],
                'examinations'   => $group->count(),
                'workers'        => $group->pluck('worker_id')->unique()->count(),
                'approved'       => $group->where('qc', MedicalQcStatus::APPROVED)->count(),
                'pending_review' => $group->where('qc', MedicalQcStatus::PENDING)->count(),
                'on_hold'        => $group->where('qc', MedicalQcStatus::HOLD)->count(),
                'rejected'       => $group->where('qc', MedicalQcStatus::REJECTED)->count(),
                'unfit'          => $group->where('fitness', 'Unfit')->count(),
                'expired'        => $group->where('expired', true)->count(),
                'successes'      => $group->where('cleared', true)->count(),
                'success_rate'   => $decided ? round($group->where('qc', MedicalQcStatus::APPROVED)->count() / $decided * 100, 1) : null,
                'avg_score'      => $scored->count() ? round($scored->avg('score'), 1) : null,
            ];
        })->sortByDesc('examinations')->values()->all();
    }

    /**
     * Per-worker history — one line per person, newest examination first.
     *
     * This is what makes the report filterable "by employee": the employee
     * picker is built from these rows, so it only ever offers people who
     * actually appear in the report being looked at.
     */
    private function byWorker(Collection $rows): array
    {
        return $rows->groupBy('worker_id')->map(function (Collection $group) {
            $first  = $group->first();
            $scored = $group->whereNotNull('score');
            $latest = $group->sortByDesc(fn ($r) => $r['exam_date']?->getTimestamp() ?? 0)->first();

            return [
                'worker_id'    => $first['worker_id'],
                'worker'       => $first['worker'],
                'worker_code'  => $first['worker_code'],
                'vendor'       => $first['vendor'],
                'project'      => $first['project'],
                'examinations' => $group->count(),
                'reexams'      => $group->where('is_reexam', true)->count(),
                'latest_exam'  => $latest['exam_date']?->toDateString(),
                'fitness'      => $latest['fitness'],
                'cleared'      => (bool) $latest['cleared'],
                'expired'      => (bool) $latest['expired'],
                'avg_score'    => $scored->count() ? round($scored->avg('score'), 1) : null,
                'latest_score' => $latest['score'],
            ];
        })->sortBy('worker')->values()->all();
    }

    /** Per-project totals — the same outcome columns, grouped by where the work is. */
    private function byProject(Collection $rows): array
    {
        return $rows->groupBy(fn ($r) => $r['project'] ?: 'Unassigned')->map(function (Collection $group, $project) {
            $decided = $group->whereIn('qc', [MedicalQcStatus::APPROVED, MedicalQcStatus::REJECTED])->count();
            $scored  = $group->whereNotNull('score');

            return [
                'project'      => $project,
                'examinations' => $group->count(),
                'workers'      => $group->pluck('worker_id')->unique()->count(),
                'successes'    => $group->where('cleared', true)->count(),
                'unfit'        => $group->where('fitness', 'Unfit')->count(),
                'pending_review' => $group->where('qc', MedicalQcStatus::PENDING)->count(),
                'success_rate' => $decided ? round($group->where('qc', MedicalQcStatus::APPROVED)->count() / $decided * 100, 1) : null,
                'avg_score'    => $scored->count() ? round($scored->avg('score'), 1) : null,
            ];
        })->sortByDesc('examinations')->values()->all();
    }

    /** Per-doctor volume and outcome — who is examining, and how it lands. */
    private function byDoctor(Collection $rows): array
    {
        return $rows->filter(fn ($r) => filled($r['doctor']))
            ->groupBy('doctor')
            ->map(function (Collection $group, $doctor) {
                $scored = $group->whereNotNull('score');

                return [
                    'doctor'       => $doctor,
                    'licence'      => $group->first()['licence'],
                    'examinations' => $group->count(),
                    'approved'     => $group->where('qc', MedicalQcStatus::APPROVED)->count(),
                    'rejected'     => $group->where('qc', MedicalQcStatus::REJECTED)->count(),
                    'on_hold'      => $group->where('qc', MedicalQcStatus::HOLD)->count(),
                    'avg_score'    => $scored->count() ? round($scored->avg('score'), 1) : null,
                ];
            })->sortByDesc('examinations')->values()->all();
    }

    /** Volume over time, oldest first — the shape a trend line wants. */
    private function byMonth(Collection $rows): array
    {
        return $rows->filter(fn ($r) => $r['exam_date'] !== null)
            ->groupBy(fn ($r) => $r['exam_date']->format('Y-m'))
            ->map(fn (Collection $group, $month) => [
                'month'        => $month,
                'label'        => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                'examinations' => $group->count(),
                'approved'     => $group->where('qc', MedicalQcStatus::APPROVED)->count(),
                'rejected'     => $group->where('qc', MedicalQcStatus::REJECTED)->count(),
                'successes'    => $group->where('cleared', true)->count(),
            ])->sortBy('month')->values()->all();
    }

    /**
     * Why certificates were refused. Reported because the pattern is the useful
     * part: forty rejections all reading "illegible document" is a scanning
     * problem, not forty unfit workers.
     */
    private function rejections(Collection $rows): array
    {
        $labels = collect(MedicalQcStatus::defaultReasons())->keyBy('value');

        return $rows->filter(fn ($r) => filled($r['reason']))
            ->groupBy('reason')
            ->map(fn (Collection $group, $reason) => [
                'reason'   => $reason,
                'label'    => $labels[$reason]['label'] ?? ucfirst(str_replace('_', ' ', $reason)),
                'count'    => $group->count(),
                'rejected' => $group->where('qc', MedicalQcStatus::REJECTED)->count(),
                'held'     => $group->where('qc', MedicalQcStatus::HOLD)->count(),
            ])->sortByDesc('count')->values()->all();
    }

    /**
     * How long the quality team takes, and how much argument a certificate
     * costs. Slow review is what turns a medical requirement into a site delay.
     */
    private function turnaround(Collection $rows): array
    {
        $decided = $rows->filter(fn ($r) => $r['qc_at'] && $r['created_at']);

        $hours = $decided->map(fn ($r) => Carbon::parse($r['created_at'])->diffInMinutes(Carbon::parse($r['qc_at'])) / 60);

        return [
            'decided'           => $decided->count(),
            'avg_hours'         => $hours->count() ? round($hours->avg(), 1) : null,
            'longest_hours'     => $hours->count() ? round($hours->max(), 1) : null,
            'avg_exchanges'     => $rows->count() ? round($rows->avg('iterations'), 2) : null,
            'needed_exchanges'  => $rows->where('iterations', '>', 0)->count(),
        ];
    }

    /* ── Export ─────────────────────────────────────────────────────────── */

    /**
     * The vendor table as spreadsheet rows — the sheet people actually take to
     * a meeting.
     *
     * @return array<int, array<int, string|int|float|null>>
     */
    public function vendorRows(array $report): array
    {
        $out = [[
            'Vendor', 'Examinations', 'Workers', 'Approved', 'Awaiting review', 'On hold',
            'Rejected', 'Unfit', 'Expired', 'Successes', 'Success rate %', 'Average health score',
        ]];

        foreach ($report['by_vendor'] as $row) {
            $out[] = [
                $row['vendor'], $row['examinations'], $row['workers'], $row['approved'],
                $row['pending_review'], $row['on_hold'], $row['rejected'], $row['unfit'],
                $row['expired'], $row['successes'], $row['success_rate'], $row['avg_score'],
            ];
        }

        return $out;
    }
}
