<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseRequest;
use App\Models\Purchase\PurchaseRfq;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfInstance;

/**
 * Compiles Purchase documents (PR / RFQ / PO) into printable PDFs using the
 * project's established dompdf driver (same as Sales proposals). Each renderer
 * eager-loads exactly what its Blade template needs and returns a configured
 * PDF instance the controller can stream (inline) or download.
 */
class PurchaseDocumentPdfService
{
    /** Internal approval sheet: lines, requester, cost centre, totals. */
    public function renderRequest(PurchaseRequest $pr): PdfInstance
    {
        $pr->loadMissing(['items', 'vendor']);

        return $this->make('pdf.purchase.request', [
            'pr'        => $pr,
            'requester' => $pr->requested_by ? User::find($pr->requested_by) : null,
            'company'   => $this->company($pr->tenant_id),
        ]);
    }

    /** Vendor-facing pricing inquiry — quantities only, no internal pricing. */
    public function renderRfq(PurchaseRfq $rfq): PdfInstance
    {
        $rfq->loadMissing(['items', 'rfqVendors.vendor']);

        return $this->make('pdf.purchase.rfq', [
            'rfq'     => $rfq,
            'company' => $this->company($rfq->tenant_id),
        ]);
    }

    /** Authoritative, company-branded order with vendor, delivery, terms. */
    public function renderOrder(PurchaseOrder $po): PdfInstance
    {
        $po->loadMissing(['items', 'vendor', 'contract']);

        return $this->make('pdf.purchase.order', [
            'po'      => $po,
            'company' => $this->company($po->tenant_id),
        ]);
    }

    /** A4 portrait is the norm for these documents. */
    private function make(string $view, array $data): PdfInstance
    {
        return Pdf::loadView($view, $data)->setPaper('a4', 'portrait');
    }

    /** Buyer/company identity for the letterhead — tolerant of a slim tenant row. */
    private function company(int $tenantId): array
    {
        $t = Tenant::find($tenantId);

        return [
            'name'    => $t->name ?? 'Company',
            'address' => $t->address ?? null,
            'gst'     => $t->gst_number ?? null,
            'email'   => $t->email ?? null,
        ];
    }
}
