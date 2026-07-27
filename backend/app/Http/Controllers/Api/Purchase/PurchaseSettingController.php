<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Services\Purchase\PurchaseSettingService;
use Illuminate\Http\Request;

/**
 * Purchase Settings — read/write the module's tenant-scoped key/value config.
 * DEFAULTS in PurchaseSettingService is the allowlist: unknown keys are ignored.
 * Writes are admin-only (route group).
 */
class PurchaseSettingController extends Controller
{
    public function __construct(private PurchaseSettingService $settings)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->settings->all($request->user()->tenant_id));
    }

    public function update(Request $request)
    {
        $rules = [
            'pur_order_prefix'   => 'nullable|string|max:20',
            'pur_request_prefix' => 'nullable|string|max:20',
            'pur_inv_prefix'     => 'nullable|string|max:20',
            'debit_note_prefix'  => 'nullable|string|max:20',
            'next_po_number'     => 'nullable|integer|min:1',
            'next_pr_number'     => 'nullable|integer|min:1',
            'pur_invoice_auto_operations_hour' => 'nullable|integer|min:0|max:23',

            'pur_company_address'      => 'nullable|string|max:1000',
            'pur_company_city'         => 'nullable|string|max:120',
            'pur_company_state'        => 'nullable|string|max:120',
            'pur_company_country_text' => 'nullable|string|max:10',
            'pur_company_zipcode'      => 'nullable|string|max:20',
            'pur_company_country_code' => 'nullable|string|max:120',

            'terms_and_conditions' => 'nullable|string|max:20000',
            'vendor_note'          => 'nullable|string|max:20000',

            'purchase_order_setting'                  => 'boolean',
            'item_by_vendor'                          => 'boolean',
            'po_only_prefix_and_number'               => 'boolean',
            'allow_vendors_to_register'               => 'boolean',
            'show_purchase_tax_column'                => 'boolean',
            'send_email_welcome_for_new_contact'      => 'boolean',
            'reset_purchase_order_number_every_month' => 'boolean',

            'pur_order_return_number_prefix' => 'nullable|string|max:20',
            'next_pur_order_return_number'   => 'nullable|integer|min:1',
        ];

        // Only validate/persist the keys actually sent, so each tab can save alone.
        $data = $request->validate(array_intersect_key($rules, $request->all()));

        return response()->json($this->settings->update($request->user()->tenant_id, $data, $request->user()));
    }
}
