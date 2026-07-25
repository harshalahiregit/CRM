<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remindable_type' => 'required|in:lead,proposal,estimate,invoice,credit_note,contract,customer',
            'remindable_id'   => 'required|integer',
            'type'            => 'required|in:call,meeting,email,whatsapp,sms,visit',
            'title'           => 'required|string|max:255',
            'notes'           => 'nullable|string',
            'due_at'          => 'required|date',
            'priority'        => 'nullable|in:low,medium,high',
            'staff_id'        => 'nullable|exists:users,id',
            'is_recurring'    => 'nullable|boolean',
            'recur_every'     => 'nullable|integer|min:1',
            'recur_type'      => 'nullable|in:day,week,month,year',
        ];
    }
}
