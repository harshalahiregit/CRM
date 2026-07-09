<?php

namespace App\Http\Requests\Sales;

use App\Models\CreditNote;
use Illuminate\Foundation\Http\FormRequest;

class RefundCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var CreditNote|null $creditNote */
        $creditNote = $this->route('creditNote');
        $max = $creditNote?->remaining ?? 0;

        return [
            'amount'         => 'required|numeric|min:0.01|max:' . $max,
            'mode'           => 'required|string',
            'transaction_id' => 'nullable|string',
            'note'           => 'nullable|string',
        ];
    }
}
