<?php

namespace App\Http\Requests\Transport;

use App\Services\Transport\TripDocumentService;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filing a POD (or other paperwork) against a trip — API-008, CTR-012, DB-009.
 *
 * CTR-012 verbatim:
 *   pod_file | multipart | FILE | Required | "allowed MIME/size" | signed upload
 *
 * The field is named `file` here rather than `pod_file`, because DB-009 is the
 * "LR/POD/EWB/attachments index" and this endpoint files any of them —
 * `document_type` says which. A field called pod_file carrying an e-way bill
 * would be the worse lie. API-008's own path is `.../pod`, and that is the
 * route, which is where the registry's naming lands.
 *
 * MIME is validated twice on purpose. Here, so the caller gets a 422 with a
 * field name; and again in the service on the file's own bytes, because this
 * layer's `mimetypes:` rule is the only gate an internal caller bypasses — and
 * because a browser-supplied content type is a claim, not a fact.
 */
class StoreTripDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file',
                'mimetypes:'.implode(',', TripDocumentService::ALLOWED_MIME),
                'max:'.(int) (TripDocumentService::MAX_BYTES / 1024),   // Laravel counts kilobytes
            ],
            'document_type' => ['nullable', 'string', Rule::in(TransportDocumentType::ALL)],
            'notes'         => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'  => 'A document file is required.',
            'file.mimetypes' => 'A document must be a PDF or an image.',
            'file.max'       => 'A document may be at most '
                .(int) (TripDocumentService::MAX_BYTES / 1024 / 1024).' MB.',
            'document_type.in' => 'Unknown document type.',
        ];
    }
}
