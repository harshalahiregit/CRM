<?php

namespace App\Http\Requests\Customer;

use App\Rules\Gstin;
use App\Rules\PhoneNumber;
use App\Rules\Pincode;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Owners must belong to the acting tenant — no cross-tenant assignment.
        $tenantId = $this->user()?->tenant_id;

        return [
            'company'          => 'required|string|max:255',
            'gst_number'       => ['nullable', 'string', new Gstin()],
            'phone'            => ['nullable', 'string', 'max:30', new PhoneNumber()],
            'website'          => 'nullable|string|max:255',
            'parent_company'   => 'nullable|string|max:255',
            'parent_client_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('clients', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'opening_balance'  => 'nullable|numeric',
            'opening_balance_date' => 'nullable|date',
            'show_primary_contact' => 'nullable|boolean',
            'vendor_id'        => 'nullable|integer',
            'lead_id'          => 'nullable|integer',

            'address'          => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:100',
            'state'            => 'nullable|string|max:100',
            'zip'              => ['nullable', 'string', new Pincode()],
            'country'          => 'nullable|string|max:100',

            'billing_street'   => 'nullable|string|max:255',
            'billing_city'     => 'nullable|string|max:100',
            'billing_state'    => 'nullable|string|max:100',
            'billing_zip'      => ['nullable', 'string', new Pincode()],
            'billing_country'  => 'nullable|string|max:100',
            'shipping_street'  => 'nullable|string|max:255',
            'shipping_city'    => 'nullable|string|max:100',
            'shipping_state'   => 'nullable|string|max:100',
            'shipping_zip'     => ['nullable', 'string', new Pincode()],
            'shipping_country' => 'nullable|string|max:100',

            'social_links'     => 'nullable|array',
            'foundation_date'  => 'nullable|date',
            'dob'              => 'nullable|date',
            'anniversary_date' => 'nullable|date',

            'default_currency' => 'nullable|string|max:3',
            'default_language' => 'nullable|string|max:40',
            'active'           => 'nullable|boolean',

            'group_ids'        => 'nullable|array',
            'group_ids.*'      => 'integer',
            'custom_fields'    => 'nullable|array',

            // Ordered account-handler ids (primary → fallbacks). Deliberately
            // nullable at the API level: the create-stepper UI enforces ≥1
            // admin, but CSV import and API callers post without admins and
            // must keep working. Tenant/role validity checked in the service.
            'admins'           => 'nullable|array',
            'admins.*'         => 'integer|distinct',

            'contacts'                => 'nullable|array',
            'contacts.*.id'           => 'nullable|integer',
            'contacts.*.first_name'   => 'required_with:contacts|string|max:255',
            'contacts.*.last_name'    => 'nullable|string|max:255',
            'contacts.*.email'        => 'nullable|email|max:255',
            'contacts.*.phone'        => 'nullable|string|max:30',
            'contacts.*.title'        => 'nullable|string|max:100',
            'contacts.*.is_primary'   => 'nullable|boolean',

            // ── Customer 360 (§11–§13) ──────────────────────────────────
            // Deliberately not validated against the option lists: a tenant can
            // edit those lists, and a value that was valid when saved must stay
            // valid afterwards. Length caps are the real guard here.
            'account_owner_id'          => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'secondary_owner_id'        => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'customer_success_owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'business_unit'             => 'nullable|string|max:80',
            'region'                    => 'nullable|string|max:80',
            'customer_type'             => 'nullable|string|max:40',
            'customer_tier'             => 'nullable|string|max:40',
            'industry'                  => 'nullable|string|max:80',
            'payment_terms'             => 'nullable|string|max:40',
            'relationship_started_at'   => 'nullable|date',
            'lifecycle_status'          => 'nullable|string|max:20',
            // §9 Customer Risk — the two indicators nobody can derive.
            // Payment, Contract, Service and Project risk are computed from
            // real signals; Relationship and Compliance have none, so they are
            // a judgement a human records. Without these rules the columns
            // existed but nothing could ever write to them, and the panel
            // showed them blank forever.
            'risk_relationship'         => ['nullable', Rule::in(['Low', 'Medium', 'High'])],
            'risk_compliance'           => ['nullable', Rule::in(['Low', 'Medium', 'High'])],
        ];
    }
}
