<?php

namespace App\Http\Requests\Shared;

use App\Support\Shared\KickoffStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Move a meeting along its lifecycle. Which target statuses are reachable, and
 * what each one requires (a delay needs a reason), is a business rule enforced
 * in KickoffMeetingService::transition — this only checks the shape.
 */
class TransitionKickoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'       => 'required|string|in:'.implode(',', KickoffStatus::ALL),
            'delay_reason' => 'nullable|string|max:255',
            'scheduled_at' => 'nullable|date',
            'minutes'      => 'nullable|string|max:5000',
        ];
    }
}
