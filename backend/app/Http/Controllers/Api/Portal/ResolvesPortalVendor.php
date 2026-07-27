<?php

namespace App\Http\Controllers\Api\Portal;

use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared plumbing for portal controllers: read the ambient vendor the middleware
 * resolved, and assert that a sub-resource actually belongs to it.
 */
trait ResolvesPortalVendor
{
    /** The caller's own vendor record (guaranteed present by the portal middleware). */
    protected function portalVendor(Request $request): Vendor
    {
        return $request->attributes->get('portalVendor');
    }

    /**
     * 404 if the model isn't owned by the caller's vendor.
     *
     * Deliberately 404, not 403: a vendor must not even learn that another
     * vendor's invoice/order exists. Every model checked here carries a
     * vendor_id (invoices, orders, debit notes, onboardings-via-vendor).
     */
    protected function assertOwned(Request $request, ?Model $model, string $label = 'Record'): void
    {
        $vendor = $this->portalVendor($request);

        abort_if(
            ! $model || (int) ($model->vendor_id ?? 0) !== (int) $vendor->id,
            404,
            "{$label} not found",
        );
    }
}
