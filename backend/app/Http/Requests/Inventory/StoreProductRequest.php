<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            // SKU/barcode are auto-generated when blank, so neither is required.
            'sku'             => 'nullable|string|max:60',
            'sku_code'        => 'nullable|string|max:60',
            'sku_name'        => 'nullable|string|max:120',
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string|max:5000',
            'barcode'         => 'nullable|string|max:60',
            // Tenant-scoped: a bare exists: would let one workspace point at another's category.
            'category_id'     => ['nullable', 'integer', Rule::exists('inventory_categories', 'id')->where('tenant_id', $tenantId)],
            'parent_id'       => ['nullable', 'integer', Rule::exists('inventory_products', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],

            // Settings master data (§10)
            'type_id'         => ['nullable', 'integer', Rule::exists('inventory_types', 'id')->where('tenant_id', $tenantId)],
            'group_id'        => ['nullable', 'integer', Rule::exists('inventory_groups', 'id')->where('tenant_id', $tenantId)],
            'subgroup_id'     => ['nullable', 'integer', Rule::exists('inventory_subgroups', 'id')->where('tenant_id', $tenantId)],
            'unit_id'         => ['nullable', 'integer', Rule::exists('inventory_units', 'id')->where('tenant_id', $tenantId)],
            'tax_id'          => ['nullable', 'integer', Rule::exists('inventory_taxes', 'id')->where('tenant_id', $tenantId)],

            // Variation attributes — each must be a row of the matching kind.
            'color_id'        => ['nullable', 'integer', Rule::exists('inventory_attributes', 'id')->where('tenant_id', $tenantId)->where('kind', 'color')],
            'model_id'        => ['nullable', 'integer', Rule::exists('inventory_attributes', 'id')->where('tenant_id', $tenantId)->where('kind', 'model')],
            'size_id'         => ['nullable', 'integer', Rule::exists('inventory_attributes', 'id')->where('tenant_id', $tenantId)->where('kind', 'size')],
            'style_id'        => ['nullable', 'integer', Rule::exists('inventory_attributes', 'id')->where('tenant_id', $tenantId)->where('kind', 'style')],

            'brand'           => 'nullable|string|max:100',
            'origin'          => 'nullable|string|max:100',
            'model'           => 'nullable|string|max:100',
            'variant'         => 'nullable|string|max:100',
            'size'            => 'nullable|string|max:50',
            'color'           => 'nullable|string|max:50',

            'base_unit'       => 'nullable|string|max:20',
            'weight'          => 'nullable|numeric|min:0',
            'volume'          => 'nullable|numeric|min:0',

            'hsn'             => 'nullable|string|max:20',
            'sac'             => 'nullable|string|max:20',
            'gst_rate'        => 'nullable|numeric|min:0|max:100',

            'warranty_months' => 'nullable|integer|min:0|max:600',
            'staff_id'        => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'route_point'     => 'nullable|string|max:120',
            // "Do not update inventory numbers"
            'without_checking_warehouse' => 'nullable|boolean',

            'min_stock'       => 'nullable|numeric|min:0',
            'max_stock'       => 'nullable|numeric|min:0|gte:min_stock',
            'reorder_point'   => 'nullable|numeric|min:0',
            'safety_stock'    => 'nullable|numeric|min:0',

            'track_batch'     => 'nullable|boolean',
            'track_serial'    => 'nullable|boolean',
            'shelf_life_days' => 'nullable|integer|min:0|max:36500',

            'cost_price'      => 'nullable|numeric|min:0',
            'sale_price'      => 'nullable|numeric|min:0',
            // Profit rate ↔ sale price are kept in step by ProductService::syncPricing.
            'profit_ratio'    => 'nullable|numeric|min:-100|max:10000',
            'sales_item_id'   => 'nullable|integer|min:1',
            'status'          => ['nullable', Rule::in(['active', 'inactive'])],

            // Optional opening balance — recorded as a real movement on create.
            'opening_stock'        => 'nullable|numeric|min:0',
            'opening_warehouse_id' => [
                'nullable', 'integer', 'required_with:opening_stock',
                Rule::exists('inventory_warehouses', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
        ];
    }
}
