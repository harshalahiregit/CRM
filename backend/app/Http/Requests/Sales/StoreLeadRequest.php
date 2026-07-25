<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => 'required|string|max:255',
            'title'             => 'nullable|string|max:255',
            'company'           => 'nullable|string|max:255',
            'email'             => 'nullable|email|max:255',
            'phone'             => 'nullable|string|max:50',
            'website'           => 'nullable|string|max:500',
            'pan'               => 'nullable|string|max:20',
            'gst'               => 'nullable|string|max:20',
            'industry'          => 'nullable|string|max:255',
            'campaign'          => 'nullable|string|max:255',
            'priority'          => 'nullable|in:low,medium,high',
            'expected_close_date' => 'nullable|date',
            'description'       => 'nullable|string',
            'lead_value'        => 'nullable|numeric|min:0',
            'conversion_chance' => 'nullable|integer|min:0|max:100',
            'country'           => 'nullable|string|max:100',
            'state'             => 'nullable|string|max:100',
            'city'              => 'nullable|string|max:100',
            'zip'               => 'nullable|string|max:20',
            'address'           => 'nullable|string',
            'status_id'         => 'nullable|exists:lead_statuses,id',
            'source_id'         => 'nullable|exists:lead_sources,id',
            'assigned_to'       => 'nullable|exists:users,id',
            'is_public'         => 'nullable|boolean',
            'referral_type'     => 'nullable|in:none,percentage,fixed',
            'referral_value'    => 'nullable|numeric|min:0',
            'referral_contact'  => 'nullable|string|max:255',
            'tags'              => 'nullable|string',
        ];
    }
}
