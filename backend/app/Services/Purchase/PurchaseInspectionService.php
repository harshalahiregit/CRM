<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseInspection;
use App\Models\Purchase\PurchaseInspectionFinding;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Purchase Inspections & Audits — the Purchase-side mirror of
 * TpvInspectionService (parity rule). CRUD over inspections + findings, and
 * escalation of a finding into a Purchase NCR.
 */
class PurchaseInspectionService
{
    public function __construct(private PurchaseNcrService $ncrs) {}

    public function list(int $tenantId, array $filters = [])
    {
        return PurchaseInspection::forTenant($tenantId)
            ->with(['vendor:id,company_name,purchase_vendor_code', 'inspector:id,name'])
            ->withCount([
                'findings',
                'findings as open_findings_count' => fn ($q) => $q->where('status', '!=', 'Closed'),
            ])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('purchase_vendor_id', $v))
            ->latest('id')
            ->get();
    }

    public function create(array $data, int $tenantId, int $userId): PurchaseInspection
    {
        return PurchaseInspection::create([
            ...$data,
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'status' => $data['status'] ?? 'Planned',
        ])->load('vendor:id,company_name,purchase_vendor_code');
    }

    public function update(PurchaseInspection $inspection, array $data): PurchaseInspection
    {
        $inspection->update($data);

        return $inspection->load('vendor:id,company_name,purchase_vendor_code');
    }

    public function detail(PurchaseInspection $inspection): PurchaseInspection
    {
        return $inspection->load(['vendor:id,company_name,purchase_vendor_code', 'inspector:id,name', 'findings.ncr:id,reference,status']);
    }

    public function delete(PurchaseInspection $inspection): void
    {
        $inspection->delete();
    }

    /* ── Findings ───────────────────────────────────────────────────────── */

    public function addFinding(PurchaseInspection $inspection, array $data): PurchaseInspectionFinding
    {
        return $inspection->findings()->create([...$data, 'tenant_id' => $inspection->tenant_id]);
    }

    public function updateFinding(PurchaseInspectionFinding $finding, array $data): PurchaseInspectionFinding
    {
        // Rule 11 (parity with TpvInspectionService) — a finding cannot progress
        // past Open (to Action or Closed) without a named responsible owner.
        $nextStatus = $data['status'] ?? $finding->status;
        $owner = array_key_exists('responsible_by', $data) ? $data['responsible_by'] : $finding->responsible_by;
        if (in_array($nextStatus, ['Action', 'Closed'], true) && empty($owner)) {
            throw new BusinessException('A responsible owner is required before an inspection finding can be actioned or closed.');
        }

        $finding->update($data);

        return $finding;
    }

    public function deleteFinding(PurchaseInspectionFinding $finding): void
    {
        $finding->delete();
    }

    /** Escalate a finding into a full Purchase NCR (once). Links back via ncr_id. */
    public function escalateToNcr(PurchaseInspectionFinding $finding, User $actor): PurchaseInspectionFinding
    {
        if ($finding->ncr_id) {
            throw new BusinessException('This finding has already been raised as an NCR.');
        }

        $inspection = $finding->inspection;
        $ncr = $this->ncrs->create([
            'title' => 'From '.$inspection->reference.': '.Str::limit($finding->description, 80),
            'purchase_vendor_id' => $inspection->purchase_vendor_id,
            'source_type' => PurchaseInspectionFinding::class,
            'source_id' => $finding->id,
            'finding' => $finding->description,
            'severity' => $finding->severity,
            'due_date' => $finding->due_date,
            'responsible_by' => $finding->responsible_by,
        ], $inspection->tenant_id, $actor->id);

        $finding->update(['ncr_id' => $ncr->id, 'status' => 'Action']);

        return $finding->load('ncr:id,reference,status');
    }
}
