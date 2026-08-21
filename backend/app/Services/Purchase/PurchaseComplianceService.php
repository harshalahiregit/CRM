<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseVendorCompliance;
use App\Support\Purchase\PurchaseComplianceCatalog as Catalog;

/**
 * Purchase compliance engine — the Purchase-side mirror of TpvComplianceService
 * (parity rule). A per-vendor register across the 14 categories; expiry drives
 * status (Rule 8). One record per vendor+category.
 */
class PurchaseComplianceService
{
    /** All 14 categories for a vendor, each with its current record (or a blank). */
    public function vendorMatrix(int $tenantId, int $vendorId): array
    {
        $records = PurchaseVendorCompliance::forTenant($tenantId)->where('purchase_vendor_id', $vendorId)
            ->get()->keyBy('category');

        return collect(Catalog::CATEGORIES)->map(function ($cat) use ($records) {
            $rec = $records->get($cat);

            return [
                'category' => $cat,
                'category_label' => Catalog::label($cat),
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

    public function upsert(int $tenantId, int $vendorId, array $data, int $userId): PurchaseVendorCompliance
    {
        return PurchaseVendorCompliance::updateOrCreate(
            ['tenant_id' => $tenantId, 'purchase_vendor_id' => $vendorId, 'category' => $data['category']],
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

    public function delete(PurchaseVendorCompliance $record): void
    {
        $record->delete();
    }

    /** Per-vendor compliance roster — % in good standing + problem counts. */
    public function roster(int $tenantId): array
    {
        $byVendor = PurchaseVendorCompliance::forTenant($tenantId)
            ->with('vendor:id,company_name,purchase_vendor_code')
            ->get()->groupBy('purchase_vendor_id');

        $total = count(Catalog::CATEGORIES);

        return $byVendor->map(function ($records) use ($total) {
            $vendor = $records->first()->vendor;
            $ok = $records->filter(fn ($r) => in_array($r->effective_status, Catalog::OK_STATUSES, true))->count();
            $problems = $records->filter(fn ($r) => in_array($r->effective_status, ['Non_Compliant', 'Expired'], true))->count();
            $expiring = $records->filter(fn ($r) => $r->effective_status === 'Expiring')->count();

            return [
                'vendor_id' => $vendor?->id,
                'vendor' => $vendor?->company_name,
                'vendor_code' => $vendor?->purchase_vendor_code,
                'tracked' => $records->count(),
                'ok' => $ok,
                'problems' => $problems,
                'expiring' => $expiring,
                'percent' => (int) round($ok / $total * 100),
            ];
        })->sortBy('percent')->values()->all();
    }
}
