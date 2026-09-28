<?php

namespace App\Http\Requests\Transport;

use Illuminate\Validation\Rule;

/** SNG-TRN-004 — driver update. Uniqueness ignores the row being edited. */
class UpdateTransportDriverRequest extends StoreTransportDriverRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $tenantId = $this->user()->tenant_id;
        $id = (int) $this->route('id');

        $rules['name'] = ['sometimes', 'required', 'string', 'max:150'];

        foreach (['driver_code' => 'driver_code', 'hr_employee_id' => 'hr_employee_id'] as $field => $column) {
            $rules[$field] = array_filter($rules[$field], fn ($r) => ! $r instanceof \Illuminate\Validation\Rules\Unique);
            $rules[$field][] = Rule::unique('transport_drivers', $column)
                ->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($id);
        }

        $rules['licence_normalized_probe'] = [
            Rule::unique('transport_drivers', 'licence_normalized')
                ->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($id),
        ];

        return $rules;
    }
}
