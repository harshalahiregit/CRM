<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A follow-up raised against a vendor.
 *
 * Mirrors StoreReminderRequest field for field, minus remindable_type/_id: the
 * subject is the vendor in the route, never something the client names. That is
 * what keeps one reminder id from being retargeted at another record.
 */
class StoreVendorReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'         => 'required|in:call,meeting,email,whatsapp,sms,visit',
            'title'        => 'required|string|max:255',
            'notes'        => 'nullable|string',
            'due_at'       => 'required|date',
            'priority'     => 'nullable|in:low,medium,high',
            'staff_id'     => 'nullable|exists:users,id',
            'is_recurring' => 'nullable|boolean',
            'recur_every'  => 'nullable|integer|min:1',
            'recur_type'   => 'nullable|in:day,week,month,year',
        ];
    }
}
