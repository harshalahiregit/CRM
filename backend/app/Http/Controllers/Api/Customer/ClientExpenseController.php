<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\Customer\Client;

class ClientExpenseController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'expenses';
    }

    /** `note` is a rich-text (notepad-style) field. */
    protected function htmlFields(): array
    {
        return ['note'];
    }

    protected function rules(Client $client): array
    {
        return [
            'name'         => 'required|string|max:255',
            'category'     => 'nullable|string|max:100',
            'amount'       => 'nullable|numeric',
            'date'         => 'nullable|date',
            'payment_mode' => 'nullable|string|max:100',
            'billable'     => 'nullable|boolean',
            'project_id'   => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('projects', 'id')->where('tenant_id', $client->tenant_id)],
            'note'         => 'nullable|string',
        ];
    }
}
