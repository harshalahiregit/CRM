<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Services\DriverDocumentService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STOS-FLEET — a driver's paperwork (T-43).
 *
 * The last thing keeping Dev 1's `/api/transport/drivers/{id}/documents` alive.
 * With this in place his driver controller can go in full rather than in half,
 * which is what his delete list is waiting on.
 *
 * A driver is addressed the way the rest of the drivers board addresses one —
 * `{source}/{person}`, a reference into the CRM directory — not by profile id.
 * Fleet stores no names, so the person is the directory entry and the profile
 * is the thin overlay hanging off it.
 *
 * Writes go through STOS-DOC's service, never into `transport_documents`
 * directly.
 */
class DriverDocumentController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private DriverDocumentService $documents)
    {
    }

    public function index(Request $request, string $source, int $person): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->documents->forDriver($this->companyId($request), $source, $person),
            'Driver documents retrieved'
        );
    }

    /** View the actual uploaded file — streamed from the private disk, tenant-scoped. */
    public function file(Request $request, int $document)
    {
        $this->denyExternal($request);

        return $this->documents->streamFile($this->companyId($request), $document);
    }

    public function store(Request $request, string $source, int $person): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            // Validated against the full applicable list rather than what the
            // picker offers: `fitness` and `permit` are deprecated for drivers
            // but still accepted, so a tenant whose policy predates the
            // 2026-09-19 approval keeps working.
            'document_type'   => ['required', 'string', Rule::in(TransportDocumentType::DRIVER_APPLICABLE)],
            'document_number' => ['nullable', 'string', 'max:80'],
            'issued_on'       => ['nullable', 'date'],
            'valid_from'      => ['nullable', 'date'],
            'valid_until'     => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            // PDF or a phone photo. 10 MB, because somebody photographing a
            // licence has no way to compress it.
            'file'            => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $result = $this->documents->file(
            $this->companyId($request),
            $source,
            $person,
            $data['document_type'],
            $data,
            $request->user()
        );

        return $this->success($result, 'Document filed', 201);
    }

    public function renew(Request $request, string $source, int $person, int $document): JsonResponse
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
     * Record a verification verdict — INTERIM, same as the vehicle side.
     *
     * The workflow and the clerk's screen belong to STOS-DOC. This exists so a
     * driving licence stops being trusted on upload alone, and so the
     * projection onto `licence_expiry` is testable today.
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
