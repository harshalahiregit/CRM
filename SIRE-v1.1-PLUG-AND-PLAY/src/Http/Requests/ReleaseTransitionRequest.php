<?php

namespace Sire\Http\Requests;

use Sire\Support\SireReleaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReleaseTransitionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(array_keys(SireReleaseStatus::TRANSITIONS))],
            'reason' => ['nullable', 'string', 'max:1000'],
            // Free text for whatever the eventual pipeline calls a build. SIRE
            // stores it and does nothing with it.
            'deployment_ref' => ['nullable', 'string', 'max:191'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Status is derived or transitioned, never posted. Accepting it would let
        // a client declare a release READY without its gates passing — the one
        // thing this whole feature exists to prevent.
        $this->request->remove('status');
        $this->request->remove('tenant_id');
        $this->request->remove('approved_by');
        $this->request->remove('gate_state');
    }
}
