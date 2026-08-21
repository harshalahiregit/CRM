<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvActivity;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerCompetency;
use App\Models\Tpv\TpvWorkerTraining;
use App\Support\Tpv\TpvWorkerStatus;

/**
 * TPV Competency & Training (Sangoe TPV §15). Manages worker competency + training
 * records and derives the Skill Matrix (Worker × Activity × Competency × Validity).
 */
class TpvCompetencyService
{
    /** Workers with competency/training counts + expiry flags — the top-level roster. */
    public function roster(int $tenantId, array $filters = [])
    {
        return TpvWorker::forTenant($tenantId)
            ->with('vendor:id,company_name')
            ->withCount([
                'competencies',
                'competencies as expiring_competencies_count' => fn ($q) => $q->whereNotNull('valid_until')
                    ->whereDate('valid_until', '<=', now()->addDays(30)),
                'trainings',
            ])
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->get();
    }

    public function workerDetail(TpvWorker $worker): array
    {
        return [
            'worker' => $worker->only(['id', 'name', 'worker_code', 'status', 'designation', 'skill_category']),
            'competencies' => $worker->competencies()->get(),
            'trainings' => $worker->trainings()->get(),
        ];
    }

    /* ── Competencies ───────────────────────────────────────────────────── */

    public function addCompetency(TpvWorker $worker, array $data): TpvWorkerCompetency
    {
        return $worker->competencies()->create([...$data, 'tenant_id' => $worker->tenant_id]);
    }

    public function updateCompetency(TpvWorkerCompetency $c, array $data): TpvWorkerCompetency
    {
        $c->update($data);

        return $c;
    }

    public function deleteCompetency(TpvWorkerCompetency $c): void
    {
        $c->delete();
    }

    /* ── Trainings ──────────────────────────────────────────────────────── */

    public function addTraining(TpvWorker $worker, array $data): TpvWorkerTraining
    {
        return $worker->trainings()->create([...$data, 'tenant_id' => $worker->tenant_id]);
    }

    public function updateTraining(TpvWorkerTraining $t, array $data): TpvWorkerTraining
    {
        $t->update($data);

        return $t;
    }

    public function deleteTraining(TpvWorkerTraining $t): void
    {
        $t->delete();
    }

    /**
     * Whether a worker holds a valid competency matching a required name.
     * Case-insensitive contains match; a valid (non-expired) record is required.
     * This is the "no competency, no authorization" rule (Rule 4).
     */
    public function workerHasCompetency(int $workerId, ?string $required): bool
    {
        if (empty($required)) {
            return true; // no requirement → nothing to gate
        }

        return TpvWorkerCompetency::where('tpv_worker_id', $workerId)
            ->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($required).'%'])
            ->where(fn ($q) => $q->whereNull('valid_until')->orwhereDate('valid_until', '>=', now()->toDateString()))
            ->exists();
    }

    /**
     * Skill matrix for one work package: each activity's required competency ×
     * each deployed worker → met / not-met. Powers the §15 matrix and Rule 4.
     */
    public function skillMatrix(int $tenantId, int $workPackageId): array
    {
        $activities = TpvActivity::where('tenant_id', $tenantId)
            ->where('work_package_id', $workPackageId)
            ->orderBy('sort_order')->get(['id', 'name', 'required_competency', 'status']);

        $workers = TpvWorker::forTenant($tenantId)
            ->where('work_package_id', $workPackageId)
            ->where('status', TpvWorkerStatus::ACTIVE)
            ->get(['id', 'name', 'worker_code']);

        $rows = $activities->map(fn ($a) => [
            'activity' => $a->name,
            'required_competency' => $a->required_competency,
            'cells' => $workers->map(fn ($w) => [
                'worker_id' => $w->id,
                'worker' => $w->name,
                'met' => $this->workerHasCompetency($w->id, $a->required_competency),
            ])->all(),
        ])->all();

        return [
            'workers' => $workers->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'worker_code' => $w->worker_code])->all(),
            'activities' => $rows,
        ];
    }
}
