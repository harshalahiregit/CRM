<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update the per-tenant helpdesk settings row (public form toggle + logo variant
 * + default department). Admin-only.
 */
class UpdateHelpdeskSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'public_form_enabled'      => 'boolean',
            'public_form_logo_variant' => 'nullable|in:with_logo,without_logo',
            'default_department_id'    => 'nullable|integer|exists:ticket_departments,id',
        ];
    }
}
