<?php

namespace App\Http\Controllers\Api\Customer;

class ClientSubscriptionController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'subscriptions';
    }

    protected function rules(): array
    {
        return [
            'name'              => 'required|string|max:255',
            'amount'            => 'nullable|numeric',
            'quantity'          => 'nullable|integer|min:1',
            'interval'          => 'nullable|string|max:30',
            'status'            => 'nullable|string|max:30',
            'next_billing_date' => 'nullable|date',
            'description'       => 'nullable|string',
        ];
    }
}
