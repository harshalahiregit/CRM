<?php

namespace App\Http\Requests\Transport;

use App\Support\Transport\TransportDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filing a compliance document against a vehicle or driver — DB-019.
 *
 * `version` is absent: STOS-DOC §26 makes versioning the system's job, not the
 * caller's, and letting a client choose one would let it overwrite history.
 *
 * The type is validated against ENUM-006 here; whether that type may be filed
 * against THIS entity kind is checked in TransportDocumentService, so the rule
 * holds however the document arrives.
 */
class StoreTransportDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type'   => ['required', 'string', Rule::in(TransportDocumentType::ALL)],
            'document_number' => ['nullable', 'string', 'max:80'],
            'issued_on'       => ['nullable', 'date'],
            'valid_from'      => ['nullable', 'date'],
            'valid_until'     => ['nullable', 'date', 'after_or_equal:valid_from'],
            'source'          => ['nullable', 'string', 'max:30'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['valid_until.after_or_equal' => 'A document cannot expire before it becomes valid.'];
    }
}
