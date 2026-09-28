<?php

namespace App\Http\Requests\Transport;

use App\Support\Transport\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move an order through SM-ORD.
 *
 * Whether the move is legal is OrderStatus::canTransition's call, in the service
 * — this only checks the target is a real state. Validating the transition here
 * too would put the state machine in two places.
 */
class TransitionTransportOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(OrderStatus::ALL)],
            // Required for a rejection; the service enforces that, because it is
            // a business rule about reviewability rather than input shape.
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
