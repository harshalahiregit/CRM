<?php

namespace App\Http\Controllers\Api\Customer;

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

    protected function rules(): array
    {
        return [
            'name'         => 'required|string|max:255',
            'category'     => 'nullable|string|max:100',
            'amount'       => 'nullable|numeric',
            'date'         => 'nullable|date',
            'payment_mode' => 'nullable|string|max:100',
            'billable'     => 'nullable|boolean',
            'project_id'   => 'nullable|integer',
            'note'         => 'nullable|string',
        ];
    }
}
