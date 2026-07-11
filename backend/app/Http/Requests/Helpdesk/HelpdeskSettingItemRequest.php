<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/update one settings-list item (priority | status | department).
 * Admin-only. Fields are permissive because the three list types share this
 * request — only the fields relevant to a given type are sent.
 */
class HelpdeskSettingItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name'             => ($this->isMethod('post') ? 'required' : 'sometimes').'|string|max:100',
            'color'            => 'nullable|string|max:9',
            'order'            => 'nullable|integer|min:0',
            'is_default'       => 'boolean',        // priorities
            'is_closed_status' => 'boolean',        // statuses
            'description'      => 'nullable|string|max:255', // departments
        ];
    }
}
