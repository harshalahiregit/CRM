<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Services\VehicleDocumentService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STOS-FLEET — a vehicle's statutory paperwork (T-57).
 *
 * Absorbed from Dev 1's retiring `/api/transport/vehicles/{id}/documents`, so
 * that endpoint can be deleted without losing a working feature.
 *
 * Writes go through STOS-DOC's service, never into `transport_documents`
 * directly — versioning and the audit trail are Person 3's and happen once, in
 * his code, however a document arrives.
 */
class VehicleDocumentController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private VehicleDocumentService $documents)
    {
    }

    public function index(Request $request, int $vehicle): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->documents->forVehicle($vehicle, $this->companyId($request)),
            'Vehicle documents retrieved'
        );
    }

    public function store(Request $request, int $vehicle): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'document_type'   => ['required', 'string', Rule::in(TransportDocumentType::VEHICLE_APPLICABLE)],
            'document_number' => ['nullable', 'string', 'max:80'],
            'issued_on'       => ['nullable', 'date'],
            'valid_from'      => ['nullable', 'date'],
            'valid_until'     => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            // PDF or a phone photo. 10 MB, because a driver photographing a
            // certificate at the roadside has no way to compress it.
            'file'            => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $result = $this->documents->file(
            $vehicle,
            $this->companyId($request),
            $data['document_type'],
            $data,
            $request->user()
        );

        return $this->success($result, 'Document filed', 201);
    }

    public function renew(Request $request, int $vehicle, int $document): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'document_number' => ['nullable', 'string', 'max:80'],
            'issued_on'       => ['nullable', 'date'],
            'valid_from'      => ['nullable', 'date'],
            'valid_until'     => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'file'            => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        return $this->success(
            $this->documents->renew($document, $this->companyId($request), $data, $request->user()),
            'Document renewed',
            201
        );
    }

    /**
     * Record a verification verdict — INTERIM.
     *
     * The verification workflow and the screen a compliance clerk actually uses
     * belong to STOS-DOC (Person 3). This exists so Fleet can stop trusting
     * unverified paperwork today and so the projection is testable; when his
     * workflow lands, it calls the same service rather than replacing it.
     */
    public function verify(Request $request, int $document): JsonResponse
    {
        $this->denyExternal($request);
        abort_unless($this->isAdmin($request), 403, 'Only an admin can verify a document.');

        $data = $request->validate([
            'verdict' => ['required', 'string', Rule::in(['VERIFIED', 'REJECTED'])],
            'reason'  => ['nullable', 'string', 'max:255'],
        ]);

        return $this->success(
            $this->documents->verify(
                $document,
                $this->companyId($request),
                $data['verdict'],
                $data['reason'] ?? null,
                $request->user()
            ),
            'Verification recorded'
        );
    }
}
