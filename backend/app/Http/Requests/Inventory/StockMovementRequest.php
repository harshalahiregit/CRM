<?php

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\Movement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One movement: receive / issue / damage / transfer / … Transfers use
 * from_warehouse_id + to_warehouse_id; everything else uses warehouse_id.
 */
class StockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $wh = fn () => Rule::exists('inventory_warehouses', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at');
        $loc = fn () => Rule::exists('inventory_locations', 'id')->where('tenant_id', $tenantId);

        return [
            'product_id' => ['required', 'integer', Rule::exists('inventory_products', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'type'       => ['required', Rule::in(array_keys(Movement::TYPES))],
            'quantity'   => 'required|numeric|gt:0',

            'warehouse_id'      => ['nullable', 'integer', 'required_unless:type,transfer', $wh()],
            'location_id'       => ['nullable', 'integer', $loc()],
            'from_warehouse_id' => ['nullable', 'integer', 'required_if:type,transfer', $wh()],
            'to_warehouse_id'   => ['nullable', 'integer', 'required_if:type,transfer', 'different:from_warehouse_id', $wh()],
            'from_location_id'  => ['nullable', 'integer', $loc()],
            'to_location_id'    => ['nullable', 'integer', $loc()],

            'reason' => 'nullable|string|max:255',
            'notes'  => 'nullable|string|max:2000',

            // Per-movement provenance (spec §warehouse). A site can *require* these
            // via its compliance switches; the service enforces that, not here.
            'gps_lat'     => 'nullable|numeric|between:-90,90',
            'gps_lng'     => 'nullable|numeric|between:-180,180',
            'geo_address' => 'nullable|string|max:255',
            // A photo taken at the point of the move, sent as a base64 data URL.
            'photo'       => 'nullable|string',
        ];
    }
}
