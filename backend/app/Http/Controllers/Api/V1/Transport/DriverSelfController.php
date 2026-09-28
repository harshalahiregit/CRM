<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Services\DriverSelfService;
use App\Support\Transport\TransportDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STOS-FLEET — the Sangoé Driver app, acting on the signed-in driver's OWN
 * profile and documents. Every action is scoped to that one driver by the
 * service; there is no id in the path a caller could point at someone else.
 *
 * auth:sanctum only (a driver's role is 'staff'); external roles are refused.
 */
class DriverSelfController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private DriverSelfService $self)
    {
    }

    /** The driver's own profile, documents and whether they are cleared to drive. */
    public function me(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->self->myProfile($this->companyId($request), (int) $request->user()->id),
            'Your driver profile'
        );
    }

    /** Upload one of the driver's own documents — filed PENDING for the office. */
    public function storeDocument(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'document_type'   => ['required', 'string', Rule::in(TransportDocumentType::DRIVER_APPLICABLE)],
            'document_number' => ['nullable', 'string', 'max:80'],
            'issued_on'       => ['nullable', 'date'],
            'valid_from'      => ['nullable', 'date'],
            'valid_until'     => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'file'            => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        return $this->success(
            $this->self->uploadDocument($this->companyId($request), (int) $request->user()->id, $data, $request->user()),
            'Document uploaded — the office will review it', 201
        );
    }
}
