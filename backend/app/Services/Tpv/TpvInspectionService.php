<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvInspection;
use App\Models\Tpv\TpvInspectionFinding;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * TPV Inspections & Audits (Sangoe TPV §22). CRUD over inspections + findings,
 * and escalation of a finding into an NCR (Plan→Inspect→Finding→Action→CAPA/NCR
 * →Verification→Close).
 */
class TpvInspectionService
{
    public function __construct(private TpvNcrService $ncrs) {}

    public function list(int $tenantId, array $filters = [])
    {
        return TpvInspection::forTenant($tenantId)
            ->with(['vendor:id,company_name,vendor_code', 'inspector:id,name'])
            ->withCount([
                'findings',
                'findings as open_findings_count' => fn ($q) => $q->where('status', '!=', 'Closed'),
            ])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->latest('id')
            ->get();
    }

    public function create(array $data, int $tenantId, int $userId): TpvInspection
    {
        return TpvInspection::create([
            ...$data,
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'status' => $data['status'] ?? 'Planned',
        ])->load('vendor:id,company_name,vendor_code');
    }

    public function update(TpvInspection $inspection, array $data): TpvInspection
    {
        $inspection->update($data);

        return $inspection->load('vendor:id,company_name,vendor_code');
    }

    public function detail(TpvInspection $inspection): TpvInspection
    {
        return $inspection->load(['vendor:id,company_name,vendor_code', 'inspector:id,name', 'findings.ncr:id,reference,status']);
    }

    public function delete(TpvInspection $inspection): void
    {
        $inspection->delete();
    }

    /* ── Findings ───────────────────────────────────────────────────────── */

    public function addFinding(TpvInspection $inspection, array $data): TpvInspectionFinding
    {
        return $inspection->findings()->create([...$data, 'tenant_id' => $inspection->tenant_id]);
    }

    public function updateFinding(TpvInspectionFinding $finding, array $data): TpvInspectionFinding
    {
        // Rule 11 (§36) — an inspection finding cannot progress past Open (to
        // Action or Closed) without a named responsible owner. Mirrors the
        // owner-gate already enforced on CAPA and NCR progression.
        $nextStatus = $data['status'] ?? $finding->status;
        $owner = array_key_exists('responsible_by', $data) ? $data['responsible_by'] : $finding->responsible_by;
        if (in_array($nextStatus, ['Action', 'Closed'], true) && empty($owner)) {
            throw new \App\Exceptions\BusinessException('A responsible owner is required before an inspection finding can be actioned or closed.');
        }

        $finding->update($data);

        return $finding;
    }

    public function deleteFinding(TpvInspectionFinding $finding): void
    {
        $finding->delete();
    }

    /** Escalate a finding into a full NCR (once). Links back via ncr_id. */
    public function escalateToNcr(TpvInspectionFinding $finding, User $actor): TpvInspectionFinding
    {
        if ($finding->ncr_id) {
            throw new BusinessException('This finding has already been raised as an NCR.');
        }

        $inspection = $finding->inspection;
        $ncr = $this->ncrs->create([
            'title' => 'From '.$inspection->reference.': '.Str::limit($finding->description, 80),
            'vendor_id' => $inspection->vendor_id,
            'project_id' => $inspection->project_id,
            'source_type' => TpvInspectionFinding::class,
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
