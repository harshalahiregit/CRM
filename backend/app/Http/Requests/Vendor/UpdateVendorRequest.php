<?php

namespace App\Http\Requests\Vendor;

use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => 'sometimes|required|string',
            'legal_name' => 'nullable|string',
            'vendor_type' => 'sometimes|required|in:standard,temporary',
            'engagements' => 'sometimes|required|array|min:1',
            'engagements.*' => 'in:purchase,tpv',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'website' => 'nullable|url',
            'category' => 'nullable|string',
            // Vendor Master profile completion (§5).
            'trade_name' => 'nullable|string',
            'subcategory' => 'nullable|string',
            'vendor_class' => 'nullable|string',
            'parent_company' => 'nullable|string',
            'cin_number' => 'nullable|string',
            'udyam_number' => 'nullable|string',
            'site_address' => 'nullable|string',
            // Master-edit context fields — columns + fillable already existed, they
            // were just missing from the request so the master screen couldn't set
            // them. `site`/`project` also feed the onboarding checklist dimensions.
            'project' => 'nullable|string',
            'site' => 'nullable|string',
            'department' => 'nullable|string',
            'client_id' => 'nullable|integer|exists:clients,id',
            'emergency_contact' => 'nullable|string',
            'internal_sponsor' => 'nullable|string',
            'contract_owner' => 'nullable|string',
            'registration_number' => 'nullable|string',
            'gst_number' => 'nullable|string',
            'pan_number' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'country' => 'nullable|string',
            'pincode' => 'nullable|string',
            'account_manager_id' => 'nullable|integer|exists:users,id',
            'status' => ['nullable', Rule::in(VendorStatus::ALL)],
            'notes' => 'nullable|string',

            // Linked login sync — blank password keeps the existing one.
            'name' => 'nullable|string|max:150',
            'password' => 'nullable|string|min:6|confirmed',

            'contacts' => 'nullable|array',
            'contacts.*.name' => 'required|string',
            'contacts.*.designation' => 'nullable|string',
            'contacts.*.email' => 'nullable|email',
            'contacts.*.phone' => 'nullable|string',
            'contacts.*.is_primary' => 'nullable|boolean',
        ];
    }
}
