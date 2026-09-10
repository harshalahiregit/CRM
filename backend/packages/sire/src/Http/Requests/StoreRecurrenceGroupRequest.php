<?php

namespace Sire\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecurrenceGroupRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'signature'   => ['nullable', 'string', 'max:191'],
            'owner_id'    => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Every statistic on a recurrence group is derived. A count someone can
        // type is a count that will be wrong, so none of these is accepted.
        foreach (['occurrence_count', 'first_occurrence_at', 'latest_occurrence_at',
                  'average_interval_days', 'recurrence_risk', 'tenant_id'] as $derived) {
            $this->request->remove($derived);
        }
    }
}
