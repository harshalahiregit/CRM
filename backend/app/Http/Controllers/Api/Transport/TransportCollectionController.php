<?php

namespace App\Http\Controllers\Api\Transport;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\RecordCollectionRequest;
use App\Http\Requests\Transport\UpdateCollectionRequest;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TripCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receivable tracking — SNG-TRN-016, API-011.
 *
 * Thin, like every other transport controller. The four nouns of the acceptance
 * criterion — due dates, blockers, follow-up, audit trail — are all served by
 * TripCollectionService, so they hold however a collection is touched.
 *
 * Nothing here posts money. Recording a receipt moves a tracked balance and
 * emits a trigger; Accounts turns that into a posting (CTR-014, "Posting event
 * generated"). FORBID-002 / LOCK-004.
 */
class TransportCollectionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripCollectionService $collections,
        private TransportTripService $trips,
    ) {
    }

    /** The receivable on a trip, if one has been opened. */
    public function show(Request $request, int $tripId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        return $this->success([
            'collection' => $this->collections->forTrip($trip->id, $tenantId),
        ], 'Collection retrieved');
    }

    /**
     * The ageing report — IDX-009's reason for existing.
     *
     * Tenant-wide rather than per-trip: nobody chases one receivable at a time,
     * and "what is 60 days overdue" is the question this table was indexed for.
     */
    public function ageing(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        return $this->success(
            $this->collections->ageing($tenantId, $request->query('as_of')),
            'Ageing retrieved'
        );
    }

    /** What to chase today. */
    public function followUpQueue(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        return $this->success([
            'collections' => $this->collections->followUpQueue($tenantId, $request->query('as_of')),
        ], 'Follow-up queue retrieved');
    }

    /** STT-011's "Create collection task". */
    public function open(Request $request, int $tripId): JsonResponse
    {
        $request->validate(['due_date' => ['nullable', 'date']]);

        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        return $this->success(
            $this->collections->open($trip, $tenantId, $request->input('due_date'), $request->user()),
            'Collection opened', 201
        );
    }

    /** API-011 — POST /trips/{trip}/collection. PERM-011. */
    public function record(RecordCollectionRequest $request, int $tripId): JsonResponse
    {
        $tenantId   = $request->user()->tenant_id;
        $collection = $this->requireCollection($tripId, $tenantId);

        return $this->success(
            $this->collections->record(
                $collection,
                (string) $request->input('amount_received'),
                $tenantId,
                $request->user(),
                $request->input('reference'),
            ),
            'Receipt recorded'
        );
    }

    /** Flag or clear a blocker, and record a chase. */
    public function update(UpdateCollectionRequest $request, int $tripId): JsonResponse
    {
        $tenantId   = $request->user()->tenant_id;
        $collection = $this->requireCollection($tripId, $tenantId);
        $data       = $request->validated();

        if (array_key_exists('blocker_reason', $data)) {
            $collection = $data['blocker_reason'] === null || $data['blocker_reason'] === ''
                ? $this->collections->clearBlocker($collection, $tenantId, $request->user())
                : $this->collections->block($collection, $data['blocker_reason'], $tenantId, $request->user());
        }

        if (array_key_exists('next_follow_up_on', $data) || array_key_exists('note', $data)) {
            $collection = $this->collections->followUp(
                $collection, $tenantId,
                $data['next_follow_up_on'] ?? null,
                $data['note'] ?? null,
                $request->user(),
            );
        }

        return $this->success($collection, 'Collection updated');
    }

    /**
     * A receivable has to have been opened before it can be chased or paid.
     *
     * Reads as a business refusal rather than a 404, because the trip DOES
     * exist and the caller needs to know the difference — "open it first" is
     * actionable, "not found" sends somebody looking for a missing trip.
     */
    private function requireCollection(int $tripId, int $tenantId)
    {
        $trip       = $this->trips->find($tripId, $tenantId);
        $collection = $this->collections->forTrip($trip->id, $tenantId);

        if (! $collection) {
            throw new BusinessException(
                'No receivable has been opened for this trip yet.'
            );
        }

        return $collection;
    }
}
