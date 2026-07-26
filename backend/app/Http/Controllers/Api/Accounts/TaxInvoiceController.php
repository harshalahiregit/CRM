<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Services\Accounts\TaxInvoiceService;
use Illuminate\Http\Request;

/**
 * Books a GST tax invoice (sales) or purchase bill from business facts — the
 * service computes the CGST/SGST or IGST split and posts the balanced voucher.
 */
class TaxInvoiceController extends Controller
{
    public function __construct(private TaxInvoiceService $taxInvoice)
    {
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type'                  => 'required|in:sales,purchase',
            'date'                  => 'required|date',
            'party_ledger_id'       => 'required|integer',
            'place_of_supply_state' => 'nullable|string|max:2',
            'narration'             => 'nullable|string|max:1000',
            'reference_no'          => 'nullable|string|max:100',
            'tds_section_code'      => 'nullable|string|max:20',
            'items'                 => 'required|array|min:1',
            'items.*.ledger_id'     => 'required|integer',
            'items.*.taxable_amount' => 'required|numeric|min:0',
            'items.*.gst_rate_id'   => 'nullable|integer',
            'items.*.hsn_sac_id'    => 'nullable|integer',
        ]);

        $voucher = $this->taxInvoice->post($data, $request->user()->tenant_id, $request->user()->id);
        return response()->json($voucher, 201);
    }
}
