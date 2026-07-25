<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\ResubmitVendorDocumentRequest;
use App\Http\Requests\Vendor\UploadVendorDocumentRequest;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Tpv\TpvOnboarding;
use App\Models\Vendor\VendorDocument;
use App\Services\Tpv\TpvOnboardingService;
use App\Services\Vendor\VendorDocumentService;
use App\Support\Purchase\PurchaseInvoiceStatus as InvStatus;
use App\Support\Purchase\PurchaseOrderStatus as PoStatus;
use Illuminate\Http\Request;

/**
 * Vendor self-service portal — read-only for v1.
 *
 * Every method reads its subject from the ambient portalVendor (resolved by the
 * EnsureVendorPortalAccess middleware from the token), never from a route
 * parameter. Sub-resource lookups go through assertOwned(), which 404s anything
 * that isn't the caller's.
 *
 * Visibility is narrower than "everything with my vendor_id": a vendor sees only
 * documents actually shared with them — POs that have been ISSUED, invoices that
 * have been APPROVED. Internal drafts never leave the building.
 */
class VendorPortalController extends Controller
{
    use ResolvesPortalVendor;

    public function __construct(
        private TpvOnboardingService $onboardingService,
        private VendorDocumentService $documentService,
    ) {
    }

    /** The caller's own vendor profile + headline account state. */
    public function me(Request $request)
    {
        $vendor = $this->portalVendor($request);
        $vendor->loadMissing(['contacts', 'accountManager:id,name,email']);

        return response()->json([
            'vendor' => $vendor,
            // A small snapshot so the portal landing can show "what's happening".
            'summary' => [
                'open_orders'         => PurchaseOrder::forTenant($vendor->tenant_id)
                                            ->where('vendor_id', $vendor->id)
                                            ->whereIn('status', [PoStatus::ISSUED, PoStatus::PARTIALLY_RECEIVED])->count(),
                'unpaid_invoices'     => PurchaseInvoice::forTenant($vendor->tenant_id)
                                            ->where('vendor_id', $vendor->id)
                                            ->whereIn('status', InvStatus::PAYABLE)->count(),
                'outstanding_balance' => (float) PurchaseInvoice::forTenant($vendor->tenant_id)
                                            ->where('vendor_id', $vendor->id)
                                            ->whereIn('status', InvStatus::PAYABLE)->sum('balance'),
            ],
        ]);
    }

    /* ── Onboarding (TPV) ──────────────────────────────────────────────── */

    /** The caller's own onboarding record + step progress, if they have one. */
    public function onboarding(Request $request)
    {
        $vendor = $this->portalVendor($request);

        $onboarding = TpvOnboarding::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->latest()
            ->first();

        if (! $onboarding) {
            return response()->json(['onboarding' => null, 'progress' => null]);
        }

        $onboarding->load(['vendor.documents']);

        return response()->json([
            'onboarding' => $onboarding,
            'progress'   => $this->onboardingService->stepStatus($onboarding),
        ]);
    }

    /* ── Statutory documents (the portal's write actions) ──────────────── */

    /** The caller's own required-vs-uploaded document checklist. */
    public function documents(Request $request)
    {
        $vendor = $this->portalVendor($request);

        return response()->json($this->documentService->checklist($vendor));
    }

    /**
     * Upload one of the caller's own compliance documents.
     *
     * The vendor is the ambient portalVendor — never a parameter — so a vendor
     * physically cannot upload against another vendor's record. Reuses the exact
     * service/validation the staff-side upload uses; only the subject differs.
     */
    public function uploadDocument(UploadVendorDocumentRequest $request)
    {
        $vendor = $this->portalVendor($request);

        $doc = $this->documentService->upload(
            $vendor,
            $request->input('type'),
            $request->file('file'),
            $request->user(),
        );

        return response()->json($doc, 201);
    }

    /** Replace the file on a rejected document — only if it's the caller's own. */
    public function resubmitDocument(ResubmitVendorDocumentRequest $request, VendorDocument $document)
    {
        $this->assertOwned($request, $document, 'Document');

        return response()->json(
            $this->documentService->resubmit($document, $request->file('file'), $request->user())
        );
    }

    /** Download the caller's own document. */
    public function downloadDocument(Request $request, VendorDocument $document)
    {
        $this->assertOwned($request, $document, 'Document');

        $file = $this->documentService->resolveDownload($document);

        return response()->download($file['path'], $file['filename'], ['Content-Type' => $file['mime']]);
    }

    /* ── Purchase orders (read-only) ───────────────────────────────────── */

    public function orders(Request $request)
    {
        $vendor = $this->portalVendor($request);

        // Draft POs are internal — a vendor sees an order only once it's issued.
        $orders = PurchaseOrder::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->where('status', '!=', PoStatus::DRAFT)
            ->latest()
            ->get(['id', 'po_number', 'title', 'status', 'total', 'currency', 'order_date', 'expected_delivery_date']);

        return response()->json($orders);
    }

    public function order(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->assertOwned($request, $purchaseOrder, 'Purchase order');
        // Even owned, a Draft is not yet shared with the vendor.
        abort_if($purchaseOrder->status === PoStatus::DRAFT, 404, 'Purchase order not found');

        // Items only — the internal buyer (creator) is not vendor-facing.
        return response()->json($purchaseOrder->load(['items']));
    }

    /* ── Invoices (read-only) ──────────────────────────────────────────── */

    public function invoices(Request $request)
    {
        $vendor = $this->portalVendor($request);

        // Only approved invoices — a Draft (unapproved) bill is internal.
        $invoices = PurchaseInvoice::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->where('status', '!=', InvStatus::DRAFT)
            ->latest()
            ->get(['id', 'invoice_number', 'title', 'status', 'total', 'amount_paid', 'amount_credited', 'balance', 'currency', 'invoice_date', 'due_date']);

        return response()->json($invoices);
    }

    public function invoice(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        $this->assertOwned($request, $purchaseInvoice, 'Invoice');
        abort_if($purchaseInvoice->status === InvStatus::DRAFT, 404, 'Invoice not found');

        // Payments and credits are shown so the vendor can reconcile, but never
        // the internal audit trail or creator notes.
        return response()->json($purchaseInvoice->load([
            'items',
            'payments:id,purchase_invoice_id,amount,payment_date,payment_mode,reference',
            'creditApplications.debitNote:id,debit_number',
        ]));
    }
}
