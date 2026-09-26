<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvVendorPpeItem;
use App\Models\Tpv\TpvWorkerPpeIssue;
use App\Services\Shared\VendorPpeItemService;

/** A TPV vendor's own PPE list. Rules live in the shared base. */
class TpvVendorPpeItemService extends VendorPpeItemService
{
    protected function modelClass(): string
    {
        return TpvVendorPpeItem::class;
    }

    protected function ownerColumn(): string
    {
        return 'vendor_id';
    }

    protected function issueModelClass(): string
    {
        return TpvWorkerPpeIssue::class;
    }

    protected function imageFolder(): string
    {
        return 'vendor-ppe/tpv';
    }
}
