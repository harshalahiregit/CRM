<?php

namespace Sire\Http\Requests;

use Sire\Models\CorrectiveAction;
use Illuminate\Foundation\Http\FormRequest;
use Sire\Http\Requests\Concerns\ChecksBoundTenant;
use Illuminate\Validation\Rule;

class StoreCapaRequest extends FormRequest
{
    use ChecksBoundTenant;

    public function authorize(): bool
    {
        return $this->assertBoundTenant('report');
    }

    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'action_type' => ['required', Rule::in(CorrectiveAction::TYPES)],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'owner_id'    => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'due_at'      => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Completion and verification are separate, audited steps. Accepting them
        // here would let one request raise an action and sign it off in one go,
        // which defeats the separation of duties the verify step exists for.
        foreach (['status', 'completed_at', 'verified_by', 'verified_at', 'effectiveness', 'tenant_id'] as $field) {
            $this->request->remove($field);
        }
    }
}
