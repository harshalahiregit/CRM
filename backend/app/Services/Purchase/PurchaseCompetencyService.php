<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerCompetency;
use Illuminate\Support\Collection;

/**
 * Purchase Competency & Skill Matrix (mirror of TPV §15 TpvCompetencyService).
 *
 * Manages worker competency records and derives the Skill Matrix
 * (Worker × Required-Competency × Validity). Purchase has no work-package /
 * activity model, so the required-competency source is the tenant Settings key
 * `workforce_required_competencies` (a comma-separated, site-wide list); this
 * service reads it through requiredCompetencies() so the gate, the matrix and the
 * UI all agree. Purchase-owned and independent of the TPV engine.
 */
class PurchaseCompetencyService
{
    /** Workers with competency counts + expiry flags — the top-level roster. */
    public function roster(int $tenantId, array $filters = []): Collection
    {
        return PurchaseWorker::forTenant($tenantId)
            ->with('vendor:id,company_name')
            ->withCount([
                'competencies',
                'competencies as expiring_competencies_count' => fn ($q) => $q->whereNotNull('valid_until')
                    ->whereDate('valid_until', '<=', now()->addDays(30)),
            ])
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('purchase_vendor_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->get();
    }

    public function workerDetail(PurchaseWorker $worker): array
    {
        return [
            'worker' => $worker->only(['id', 'full_name', 'worker_code', 'status', 'designation']),
            'competencies' => $worker->competencies()->get(),
        ];
    }

    /* ── Competencies ───────────────────────────────────────────────────── */

    public function addCompetency(PurchaseWorker $worker, array $data): PurchaseWorkerCompetency
    {
        return $worker->competencies()->create([
            ...$data,
            'tenant_id'          => $worker->tenant_id,
            'purchase_vendor_id' => $worker->purchase_vendor_id,
        ]);
    }

    public function updateCompetency(PurchaseWorkerCompetency $c, array $data): PurchaseWorkerCompetency
    {
        $c->update($data);

        return $c;
    }

    public function deleteCompetency(PurchaseWorkerCompetency $c): void
    {
        $c->delete();
    }

    /**
     * Whether a worker holds a valid competency matching a required name.
     * Case-insensitive contains match; a valid (non-expired) record is required.
     * This is the "no competency, no authorization" rule (mirror of TPV Rule 4).
     */
    public function workerHasCompetency(int $workerId, ?string $required): bool
    {
        if (empty($required)) {
            return true; // no requirement → nothing to gate
        }

        return PurchaseWorkerCompetency::where('purchase_worker_id', $workerId)
            ->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($required).'%'])
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()))
            ->exists();
    }

    /**
     * The site-wide required competencies for a tenant, from Settings. Purchase
     * carries no per-activity requirement, so this is the single source the gate
     * and skill matrix read. Empty ⇒ the gate does not bite (graceful default).
     */
    public function requiredCompetencies(int $tenantId): Collection
    {
        $raw = (string) app(PurchaseSettingService::class)->get($tenantId, 'workforce_required_competencies');

        return collect(preg_split('/[,;\n]+/', $raw))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Competencies a worker is missing against the tenant requirement — the
     * unmet names, or an empty collection when the worker is fully covered (or
     * nothing is required).
     */
    public function missingFor(PurchaseWorker $worker): Collection
    {
        $required = $this->requiredCompetencies((int) $worker->tenant_id);
        if ($required->isEmpty()) {
            return collect();
        }

        return $required->reject(fn ($r) => $this->workerHasCompetency($worker->id, $r))->values();
    }

    /**
     * Skill matrix for one vendor's workforce: each required competency × each
     * worker → met / not-met. Purchase groups workers by vendor (there is no work
     * package), so the matrix is per vendor. When no requirement is configured the
     * `requirements` rows are empty and the matrix simply lists each worker's held
     * competencies — mirrors the TPV §15 shape.
     */
    public function skillMatrix(int $tenantId, int $vendorId): array
    {
        $required = $this->requiredCompetencies($tenantId);

        $workers = PurchaseWorker::forTenant($tenantId)
            ->where('purchase_vendor_id', $vendorId)
            ->with('competencies')
            ->orderByDesc('id')
            ->get(['id', 'full_name', 'worker_code', 'status', 'purchase_vendor_id', 'tenant_id']);

        $rows = $required->map(fn ($comp) => [
            'required_competency' => $comp,
            'cells' => $workers->map(fn ($w) => [
                'worker_id' => $w->id,
                'worker' => $w->full_name,
                'met' => $this->workerHasCompetency($w->id, $comp),
            ])->all(),
        ])->all();

        return [
            'workers' => $workers->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->full_name,
                'worker_code' => $w->worker_code,
                'competencies' => $w->competencies->map(fn ($c) => [
                    'name' => $c->name, 'category' => $c->category, 'status' => $c->status,
                ])->all(),
            ])->all(),
            'requirements' => $rows,
        ];
    }
}
