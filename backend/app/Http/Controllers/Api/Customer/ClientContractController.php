<?php

namespace App\Http\Controllers\Api\Customer;

class ClientContractController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'contracts';
    }

    protected function rules(): array
    {
        return [
            'subject'       => 'required|string|max:255',
            'contract_type' => 'nullable|string|max:100',
            'value'         => 'nullable|numeric',
            'start_date'    => 'nullable|date',
            'end_date'      => 'nullable|date',
            'status'        => 'nullable|string|max:30',
            'description'   => 'nullable|string',
        ];
    }
}
