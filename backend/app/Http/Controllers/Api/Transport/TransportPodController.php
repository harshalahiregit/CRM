<?php

namespace App\Http\Controllers\Api\Transport;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\RejectTripDocumentRequest;
use App\Http\Requests\Transport\StoreTripDocumentRequest;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TripDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trip documents and POD — SNG-TRN-014, API-008.
 *
 * Thin, like every other transport controller: FormRequest → service →
 * ApiResponse, no queries and no rules. STT-008's guard, CTR-012's immutability
 * and the billing gate all live in TripDocumentService so they hold however a
 * document is filed — including from the mobile path SNG-TRN-026 will add.
 *
 * Tenant scoping is never re-implemented per method; lookups go through a
 * service `find()` that filters by tenant AT the point of lookup. Route-model
 * binding is deliberately not used, for the reason the consignment controller
 * records: it resolves a cross-tenant id first and leaves the check to be
 * remembered afterwards.
 */
class TransportPodController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripDocumentService $documents,
        private TransportTripService $trips,
    ) {
    }

    /**
     * Everything filed against a trip, and whether it may be billed.
     *
     * The readiness verdict is returned beside the rows rather than left for the
     * client to infer from them. The rule is "POD required before billable
     * unless approved exception" — a screen deciding that for itself would
     * eventually disagree with the server, and the waiver arm is not visible
     * from this list at all.
     */
    public function index(Request $request, int $tripId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        return $this->success([
            'documents' => $this->documents->forTrip($trip->id, $tenantId),
            'billing'   => $this->documents->billingReadiness($trip, $tenantId),
        ], 'Trip documents retrieved');
    }

    /** API-008 — POST /trips/{trip}/pod. PERM-010 transport.pod.submit. */
    public function store(StoreTripDocumentRequest $request, int $tripId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        return $this->success(
            $this->documents->file(
                $trip,
                $request->file('file'),
                $request->validated(),
                $tenantId,
                $request->user(),
            ),
            'Document filed', 201
        );
    }

    /** STT-008 — verify, and unlock billing. */
    public function verify(Request $request, int $tripId, int $documentId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $this->trips->find($tripId, $tenantId);          // tenant + existence gate
        $document = $this->documents->find($documentId, $tenantId);

        $this->assertBelongsToTrip((int) $document->trip_id, $tripId);

        return $this->success(
            $this->documents->verify($document, $tenantId, $request->user()),
            'Document verified'
        );
    }

    /** Refusing is the same authority as accepting. */
    public function reject(RejectTripDocumentRequest $request, int $tripId, int $documentId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $this->trips->find($tripId, $tenantId);
        $document = $this->documents->find($documentId, $tenantId);

        $this->assertBelongsToTrip((int) $document->trip_id, $tripId);

        return $this->success(
            $this->documents->reject($document, (string) $request->input('reason'), $tenantId, $request->user()),
            'Document rejected'
        );
    }

    /**
     * The document must belong to the trip in the path.
     *
     * Both ids are tenant-checked already, so this is not a security boundary —
     * it stops a correct-looking URL that pairs trip 4 with trip 9's POD from
     * quietly verifying the wrong one, and unlocking billing on a trip that has
     * no proof of anything.
     */
    private function assertBelongsToTrip(int $documentTripId, int $tripId): void
    {
        if ($documentTripId !== $tripId) {
            throw new BusinessException('That document belongs to a different trip.', 404);
        }
    }
}
