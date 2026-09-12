<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Consignment creation — `STOS-REQ-ORD-004`, `CTD-002`, `CTD-003`.
 *
 * Shape validation only. The gates that need the database — the order must
 * exist in this workspace — live in ConsignmentService, so they apply however a
 * consignment arrives and not only through this endpoint. The tenant-scoped
 * `exists` rule below is belt to that service's braces: it fails the request
 * with a field error rather than a 422 from deeper in, which is what a form
 * needs to highlight the right input.
 *
 * ── WHAT THIS DELIBERATELY DOES NOT ACCEPT ───────────────────────────────
 *   consignment_number  allocated by the numbering engine. Accepting one would
 *                       let a client choose its own reference.
 *   customer_id         taken FROM the order by the service (CTD-002). Two
 *                       sources for one fact is two chances to disagree.
 *   status              there is none. STOS-CTD §11 puts consignment status in
 *                       a lifecycle engine that does not exist — D-44.
 *
 * Any of those may be POSTed; they are simply ignored, because the service
 * accepts only its WRITABLE list. Rejecting them outright would break a client
 * that round-trips a consignment it has just read.
 */
class StoreConsignmentRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission), not this class's. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            // Tenant-scoped: a bare exists:transport_orders,id would accept
            // another workspace's order and leak its existence.
            'order_id' => [
                'required', 'integer',
                Rule::exists('transport_orders', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],

            // STOS-CTD §4 — a search path in its own right, so it is bounded to
            // the column width rather than left free.
            'customer_reference' => ['nullable', 'string', 'max:120'],

            // STOS-CTD §8's "other cargo references" — what is being moved when
            // there is no container.
            'cargo_description' => ['nullable', 'string', 'max:2000'],

            // FRS TRP-P0-001's field list.
            'service_type'     => ['nullable', 'string', 'max:60'],
            'special_handling' => ['nullable', 'string', 'max:2000'],

            // Cargo measures. Bounded at both ends: a negative weight is not a
            // correction and a package count of zero is not a consignment.
            'package_count'   => ['nullable', 'integer', 'min:1', 'max:999999'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'volume_cbm'      => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
        ];
    }

    public function messages(): array
    {
        return [
            'order_id.required'      => 'Choose the order this consignment belongs to.',
            'order_id.exists'        => 'That order does not exist in this workspace.',
            'package_count.min'      => 'A consignment carries at least one package.',
            'gross_weight_kg.min'    => 'Weight cannot be negative.',
            'volume_cbm.min'         => 'Volume cannot be negative.',
        ];
    }
}
