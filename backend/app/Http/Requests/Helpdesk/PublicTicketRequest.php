<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Foundation\Http\FormRequest;

class PublicTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'subject'  => 'required|string|max:255',
            'message'  => 'required|string|max:5000',
            'priority' => 'nullable|in:low,medium,high,urgent',
            // Honeypot: a hidden field real users never fill. Bots do → reject.
            'hp'       => 'prohibited',
        ];
    }
}
