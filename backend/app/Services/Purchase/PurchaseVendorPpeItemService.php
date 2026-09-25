<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseVendorPpeItem;
use App\Models\Purchase\PurchaseWorkerPpeIssue;
use App\Services\Shared\VendorPpeItemService;

/** A Purchase vendor's own PPE list. Rules live in the shared base. */
class PurchaseVendorPpeItemService extends VendorPpeItemService
{
    protected function modelClass(): string
    {
        return PurchaseVendorPpeItem::class;
    }

    protected function ownerColumn(): string
    {
        return 'purchase_vendor_id';
    }

    protected function issueModelClass(): string
    {
        return PurchaseWorkerPpeIssue::class;
    }

    protected function imageFolder(): string
    {
        return 'vendor-ppe/purchase';
    }
}
