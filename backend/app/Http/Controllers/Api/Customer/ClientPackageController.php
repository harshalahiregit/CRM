<?php

namespace App\Http\Controllers\Api\Customer;

class ClientPackageController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'packages';
    }

    protected function rules(): array
    {
        return [
            'package_number'  => 'required|string|max:255',
            'description'     => 'nullable|string|max:255',
            'courier_company' => 'nullable|string|max:150',
            'supplier'        => 'nullable|string|max:150',
            'value'           => 'nullable|numeric',
            'weight'          => 'nullable|string|max:50',
            'status'          => 'nullable|string|max:30',
            'date'            => 'nullable|date',
        ];
    }
}
