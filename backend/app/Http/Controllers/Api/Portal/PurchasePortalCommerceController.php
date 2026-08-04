<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseContract;
use App\Models\Purchase\PurchaseDebitNote;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseQuotation;
use App\Models\Purchase\PurchaseVendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Purchase Vendor Portal — read-only commercial documents. Every list/show is
 * scoped to the authenticated PurchaseVendor by purchase_vendor_id (payments via
 * their invoice). A vendor can never see another vendor's records; no vendor_id is
 * ever read from the request. Purchase-owned.
 */
class PurchasePortalCommerceController extends Controller
{
    /* ── Lists ───────────────────────────────────────────────────────────── */

    public function orders(Request $request)
    {
        return response()->json($this->scoped($request, PurchaseOrder::class)->latest('id')->get());
    }

    public function quotations(Request $request)
    {
        return response()->json($this->scoped($request, PurchaseQuotation::class)->latest('id')->get());
    }

    public function contracts(Request $request)
    {
        return response()->json($this->scoped($request, PurchaseContract::class)->latest('id')->get());
    }

    public function invoices(Request $request)
    {
        return response()->json($this->scoped($request, PurchaseInvoice::class)->latest('id')->get());
    }

    public function debitNotes(Request $request)
    {
        return response()->json($this->scoped($request, PurchaseDebitNote::class)->latest('id')->get());
    }

    /** Payments are scoped through their invoice's purchase_vendor_id. */
    public function payments(Request $request)
    {
        $vendor = $this->vendor($request);

        $payments = PurchaseInvoicePayment::forTenant($vendor->tenant_id)
            ->whereHas('invoice', fn ($q) => $q->where('purchase_vendor_id', $vendor->id))
            ->with(['invoice:id,invoice_number,total,balance'])
            ->latest('payment_date')
            ->get();

        return response()->json($payments);
    }

    /* ── Detail (owned) ──────────────────────────────────────────────────── */

    public function order(Request $request, int $id)
    {
        return response()->json($this->owned($request, PurchaseOrder::class, $id));
    }

    public function quotation(Request $request, int $id)
    {
        return response()->json($this->owned($request, PurchaseQuotation::class, $id));
    }

    public function contract(Request $request, int $id)
    {
        return response()->json($this->owned($request, PurchaseContract::class, $id));
    }

    public function invoice(Request $request, int $id)
    {
        return response()->json($this->owned($request, PurchaseInvoice::class, $id));
    }

    public function debitNote(Request $request, int $id)
    {
        return response()->json($this->owned($request, PurchaseDebitNote::class, $id));
    }

    /* ── scoping ─────────────────────────────────────────────────────────── */

    private function vendor(Request $request): PurchaseVendor
    {
        $vendor = $request->user();
        // #45 — 403, not 401: an authenticated identity of the WRONG TYPE is a
        // permission failure, and a 401 here would clear the caller's session.
        // EnsurePurchaseVendorPortalAccess already answers this case with 403;
        // this is the same answer for the defence-in-depth check.
        abort_unless($vendor instanceof PurchaseVendor, 403, 'This area is for Purchase vendor accounts only.');

        return $vendor;
    }

    /** Tenant + own-vendor query for a commercial model. */
    private function scoped(Request $request, string $modelClass)
    {
        $vendor = $this->vendor($request);

        return $modelClass::forTenant($vendor->tenant_id)->where('purchase_vendor_id', $vendor->id);
    }

    private function owned(Request $request, string $modelClass, int $id): Model
    {
        $model = $this->scoped($request, $modelClass)->find($id);
        abort_unless($model, 404, 'Record not found');

        return $model;
    }
}
