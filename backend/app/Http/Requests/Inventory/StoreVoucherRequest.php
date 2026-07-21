<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One request for all four voucher types. Type-specific requirements are applied
 * conditionally from the {type} route segment, so a transfer must name a source
 * and destination while a receipt only needs its target warehouse.
 */
class StoreVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $type = $this->route('type');

        $wh = fn () => Rule::exists('inventory_warehouses', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        $rules = [
            'date_c'       => 'nullable|date',
            'date_add'     => 'nullable|date',
            'description'  => 'nullable|string|max:5000',
            'warehouse_id' => ['nullable', 'integer', $wh()],
            'staff_id'     => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'route_point'  => 'nullable|string|max:120',

            // Line grid — the form always submits the full set.
            'items'                     => 'present|array|max:200',
            'items.*.product_id'        => ['required', 'integer', Rule::exists('inventory_products', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'items.*.quantity'          => 'required|numeric|min:0',
            'items.*.unit_price'        => 'nullable|numeric|min:0',
            'items.*.tax_rate'          => 'nullable|numeric|min:0|max:100',
            'items.*.discount'          => 'nullable|numeric|min:0',
            'items.*.warehouse_id'      => ['nullable', 'integer', $wh()],
            'items.*.from_warehouse_id' => ['nullable', 'integer', $wh()],
            'items.*.to_warehouse_id'   => ['nullable', 'integer', $wh()],
            'items.*.lot_number'        => 'nullable|string|max:60',
            'items.*.expiry_date'       => 'nullable|date',
            'items.*.note'              => 'nullable|string|max:255',
        ];

        return array_merge($rules, match ($type) {
            'receipt' => [
                'warehouse_id'  => ['required', 'integer', $wh()],
                'supplier_name' => 'nullable|string|max:150',
                'supplier_code' => 'nullable|string|max:60',
                'deliver_name'  => 'nullable|string|max:150',
                'invoice_no'    => 'nullable|string|max:60',
                'expiry_date'   => 'nullable|date',
                'expense_type'  => ['nullable', Rule::in(['CAPEX', 'OPEX'])],
                'department'    => 'nullable|string|max:120',
                'requester'     => 'nullable|string|max:120',
                'buyer_id'      => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
                // Soft links: no PO module here, and Projects is another module.
                'pr_order_id'   => 'nullable|integer|min:1',
                'project_id'    => 'nullable|integer|min:1',
            ],
            'delivery' => [
                'warehouse_id'  => ['required', 'integer', $wh()],
                'customer_name' => 'nullable|string|max:150',
                'customer_id'   => 'nullable|integer|min:1',
                'address'       => 'nullable|string|max:255',
                'invoice_no'    => 'nullable|string|max:60',
            ],
            'internal' => [
                // Every line moves between two named sites.
                'items.*.from_warehouse_id' => ['required', 'integer', $wh()],
                'items.*.to_warehouse_id'   => ['required', 'integer', 'different:items.*.from_warehouse_id', $wh()],
            ],
            'loss_adjustment' => [
                'warehouse_id'    => ['required', 'integer', $wh()],
                'adjustment_type' => ['required', Rule::in(['loss', 'adjustment'])],
                'reason'          => 'nullable|string|max:2000',
            ],
            default => [],
        });
    }

    public function messages(): array
    {
        return [
            'items.*.to_warehouse_id.different' => 'A transfer line must move between two different warehouses.',
            'items.*.product_id.required'       => 'Every line needs a product.',
        ];
    }
}
