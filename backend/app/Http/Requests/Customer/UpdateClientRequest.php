<?php

namespace App\Http\Requests\Customer;

use App\Rules\Gstin;
use App\Rules\PhoneNumber;
use App\Rules\Pincode;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Apply a format rule only when the value is actually being changed.
     *
     * Format rules were added to fields that already hold years of unvalidated
     * data — two of the four seeded clients carry a malformed GSTIN. Enforcing
     * unconditionally would mean someone editing a customer's phone number gets
     * blocked by a GST field they never touched and may not be able to correct.
     *
     * So on update, an unchanged value is grandfathered and only a genuine edit
     * has to satisfy the new rule. Creates are always validated, so no new bad
     * data can get in; the legacy rows simply have to be fixed deliberately
     * rather than ambushing the next person to open the form.
     */
    private function whenChanged(string $field, array $base, object $rule): array
    {
        $client = $this->route('client');
        $stored = is_object($client) ? ($client->{$field} ?? null) : null;
        $submitted = $this->input($field);

        $unchanged = $stored !== null
            && $submitted !== null
            && (string) $stored === (string) $submitted;

        return $unchanged ? $base : [...$base, $rule];
    }

    public function rules(): array
    {
        // Owners must belong to the acting tenant — no cross-tenant assignment.
        $tenantId = $this->user()?->tenant_id;

        return [
            'company'          => 'sometimes|required|string|max:255',
            'gst_number'       => $this->whenChanged('gst_number', ['nullable', 'string'], new Gstin()),
            'phone'            => $this->whenChanged('phone', ['nullable', 'string', 'max:30'], new PhoneNumber()),
            'website'          => 'nullable|string|max:255',
            'parent_company'   => 'nullable|string|max:255',
            // A company cannot be its own parent (and the parent must be in this tenant).
            'parent_client_id' => ['nullable', 'integer',
                \Illuminate\Validation\Rule::notIn(array_filter([$this->route('client')?->id])),
                \Illuminate\Validation\Rule::exists('clients', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'opening_balance'  => 'nullable|numeric',
            'opening_balance_date' => 'nullable|date',
            'show_primary_contact' => 'nullable|boolean',
            'vendor_id'        => 'nullable|integer',

            'address'          => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:100',
            'state'            => 'nullable|string|max:100',
            'zip'              => $this->whenChanged('zip', ['nullable', 'string'], new Pincode()),
            'country'          => 'nullable|string|max:100',

            'billing_street'   => 'nullable|string|max:255',
            'billing_city'     => 'nullable|string|max:100',
            'billing_state'    => 'nullable|string|max:100',
            'billing_zip'      => $this->whenChanged('billing_zip', ['nullable', 'string'], new Pincode()),
            'billing_country'  => 'nullable|string|max:100',
            'shipping_street'  => 'nullable|string|max:255',
            'shipping_city'    => 'nullable|string|max:100',
            'shipping_state'   => 'nullable|string|max:100',
            'shipping_zip'     => $this->whenChanged('shipping_zip', ['nullable', 'string'], new Pincode()),
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

            'contacts'                => 'nullable|array',
            'contacts.*.id'           => 'nullable|integer',
            'contacts.*.first_name'   => 'required_with:contacts|string|max:255',
            'contacts.*.last_name'    => 'nullable|string|max:255',
            'contacts.*.email'        => 'nullable|email|max:255',
            'contacts.*.phone'        => 'nullable|string|max:30',
            'contacts.*.title'        => 'nullable|string|max:100',
            'contacts.*.is_primary'   => 'nullable|boolean',

            // Push the new billing/shipping address onto existing documents
            // (including paid). Invoices+estimates vs credit notes are separate.
            'apply_to_previous_documents'    => 'nullable|boolean',
            'apply_to_previous_credit_notes' => 'nullable|boolean',

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
        ];
    }
}
