<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOfferStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'           => 'required|in:Generated,Sent,Accepted,Rejected',
            'rejection_reason' => 'nullable|string|max:500',
        ];
    }
}
