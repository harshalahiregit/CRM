<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvVendorCompliance;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\ComplianceCatalog;

/**
 * TPV Compliance engine (Sangoe TPV §21). A per-vendor register across the 14
 * categories; expiry drives status (Rule 8). One record per vendor+category.
 */
class TpvComplianceService
{
    /** All 14 categories for a vendor, each with its current record (or a blank). */
    public function vendorMatrix(int $tenantId, int $vendorId): array
    {
        $records = TpvVendorCompliance::forTenant($tenantId)->where('vendor_id', $vendorId)
            ->get()->keyBy('category');

        return collect(ComplianceCatalog::CATEGORIES)->map(function ($cat) use ($records) {
            $rec = $records->get($cat);

            return [
                'category' => $cat,
                'category_label' => ComplianceCatalog::label($cat),
                'id' => $rec?->id,
                'status' => $rec ? $rec->effective_status : 'Under_Review',
                'stored_status' => $rec?->status,
                'requirement' => $rec?->requirement,
                'valid_until' => $rec?->valid_until?->toDateString(),
                'notes' => $rec?->notes,
                'reviewed_at' => $rec?->reviewed_at?->toIso8601String(),
            ];
        })->all();
    }

    public function upsert(int $tenantId, int $vendorId, array $data, int $userId): TpvVendorCompliance
    {
        return TpvVendorCompliance::updateOrCreate(
            ['tenant_id' => $tenantId, 'vendor_id' => $vendorId, 'category' => $data['category']],
            [
                'status' => $data['status'] ?? 'Under_Review',
                'requirement' => $data['requirement'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
            ]
        );
    }

    public function delete(TpvVendorCompliance $record): void
    {
        $record->delete();
    }

    /**
     * One vendor's compliance score — % in good standing + problem/expiring counts,
     * over the full category set. Shared by the roster and the renewal assessment so
     * both read the same number.
     */
    public function scoreFor(int $tenantId, int $vendorId): array
    {
        $records = TpvVendorCompliance::forTenant($tenantId)->where('vendor_id', $vendorId)->get();
        $total = count(ComplianceCatalog::CATEGORIES);
        $ok = $records->filter(fn ($r) => in_array($r->effective_status, ComplianceCatalog::OK_STATUSES, true))->count();
        $problems = $records->filter(fn ($r) => in_array($r->effective_status, ['Non_Compliant', 'Expired'], true))->count();
        $expiring = $records->filter(fn ($r) => $r->effective_status === 'Expiring')->count();

        return [
            'tracked'  => $records->count(),
            'ok'       => $ok,
            'problems' => $problems,
            'expiring' => $expiring,
            'percent'  => $total > 0 ? (int) round($ok / $total * 100) : 0,
        ];
    }

    /** Per-vendor compliance roster — % in good standing + problem counts. */
    public function roster(int $tenantId): array
    {
        $byVendor = TpvVendorCompliance::forTenant($tenantId)->with('vendor:id,company_name,vendor_code')
            ->get()->groupBy('vendor_id');

        $total = count(ComplianceCatalog::CATEGORIES);

        return $byVendor->map(function ($records) use ($total) {
            $vendor = $records->first()->vendor;
            $ok = $records->filter(fn ($r) => in_array($r->effective_status, ComplianceCatalog::OK_STATUSES, true))->count();
            $problems = $records->filter(fn ($r) => in_array($r->effective_status, ['Non_Compliant', 'Expired'], true))->count();
            $expiring = $records->filter(fn ($r) => $r->effective_status === 'Expiring')->count();

            return [
                'vendor_id' => $vendor?->id,
                'vendor' => $vendor?->company_name,
                'vendor_code' => $vendor?->vendor_code,
                'tracked' => $records->count(),
                'ok' => $ok,
                'problems' => $problems,
                'expiring' => $expiring,
                'percent' => (int) round($ok / $total * 100),
            ];
        })->sortBy('percent')->values()->all();
    }
}
