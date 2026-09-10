<?php

namespace Sire\Http\Requests;

use Sire\Models\Release;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReleaseRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'version'      => ['required', 'string', 'max:64'],
            'name'         => ['nullable', 'string', 'max:160'],
            'release_type' => ['required', Rule::in(Release::TYPES)],
            'release_date' => ['nullable', 'date'],
            'status'       => ['nullable', Rule::in(Release::STATUSES)],
            'owner_id'     => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'summary'      => ['nullable', 'string', 'max:20000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Shipping and rolling back are transitions with their own endpoints,
        // capabilities and audit entries — not fields on a form.
        $this->request->remove('released_at');
        $this->request->remove('released_by');
        $this->request->remove('tenant_id');
    }
}
