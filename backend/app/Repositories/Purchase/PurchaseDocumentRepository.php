<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseVendor;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class PurchaseDocumentRepository extends BaseRepository
{
    protected string $modelClass = PurchaseDocument::class;

    /** All Purchase documents for a vendor, newest first (drives the checklist). */
    public function forVendor(PurchaseVendor $vendor): Collection
    {
        return PurchaseDocument::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->latest()
            ->get();
    }

    /** Find a vendor's document of a given type (for upload/replace). */
    public function findByType(PurchaseVendor $vendor, string $type): ?PurchaseDocument
    {
        return PurchaseDocument::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->where('type', $type)
            ->first();
    }
}
