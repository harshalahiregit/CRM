<?php

namespace App\Http\Controllers\Api\Customer;

class ClientPreAlertController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'preAlerts';
    }

    protected function rules(): array
    {
        return [
            'tracking_number' => 'required|string|max:255',
            'courier_company' => 'nullable|string|max:150',
            'supplier'        => 'nullable|string|max:150',
            'purchase_price'  => 'nullable|numeric',
            'delivery_date'   => 'nullable|date',
            'status'          => 'nullable|string|max:30',
            'description'     => 'nullable|string',
        ];
    }
}
