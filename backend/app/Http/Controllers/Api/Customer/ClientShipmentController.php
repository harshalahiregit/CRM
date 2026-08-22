<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\Customer\Client;

class ClientShipmentController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'shipments';
    }

    protected function rules(Client $client): array
    {
        return [
            'shipment_number' => 'required|string|max:255',
            'origin'          => 'nullable|string|max:150',
            'destination'     => 'nullable|string|max:150',
            'courier_company' => 'nullable|string|max:150',
            'weight'          => 'nullable|string|max:50',
            'value'           => 'nullable|numeric',
            'status'          => 'nullable|string|max:30',
            'date'            => 'nullable|date',
        ];
    }
}
