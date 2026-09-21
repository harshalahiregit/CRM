<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\MarkInvoicedRequest;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TripBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Billing trigger — SNG-TRN-015, API-010.
 *
 * Thin, like every other transport controller. Every precondition lives in
 * TripBillingService so the rule holds however billing is triggered, including
 * from the Accounts bridge SNG-TRN-024 will add.
 *
 * Nothing here creates an invoice. API-010 is "Prepare customer billing" and
 * that is exactly its scope — Transport says a trip may be billed and for how
 * much; Accounts raises the invoice and emits EVT-010. FORBID-002 / LOCK-004.
 */
class TransportBillingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripBillingService $billing,
        private TransportTripService $trips,
    ) {
    }

    /**
     * Whether this trip may be billed, and why — without doing anything.
     *
     * A pure read, so a screen can explain the blocker before offering the
     * button. It returns the same sentence the refusal would carry, so what a
     * user is told beforehand matches what they would be told after.
     */
    public function show(Request $request, int $tripId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        return $this->success([
            'readiness' => $this->billing->readiness($trip, $tenantId),
            'bill'      => $this->billing->forTrip($trip->id, $tenantId),
        ], 'Billing readiness retrieved');
    }

    /** API-010 — POST /trips/{trip}/bill. PERM: transport.billing.prepare. */
    public function store(Request $request, int $tripId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        return $this->success(
            $this->billing->prepare($trip, $tenantId, $request->user()),
            'Billing prepared', 201
        );
    }

    /**
     * Accounts has raised the invoice — STT-010, `billable → billed`.
     *
     * THE ROUTE THAT WAS MISSING (D-106). `TripBill::markInvoiced()` shipped on
     * the 17th with no caller and no route, which left `collection_pending`
     * unreachable and P1's closure work plumbed but dead. A handover is not
     * complete when the method exists — only when the other side can reach it.
     *
     * Records the linkage and moves the trip. It does NOT create an invoice;
     * EVT-010 `InvoicePosted` is Accounts' to emit. FORBID-002, LOCK-004.
     */
    public function invoiced(MarkInvoicedRequest $request, int $tripId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        return $this->success(
            $this->billing->markInvoiced(
                $trip, (int) $request->input('invoice_id'), $tenantId, $request->user()
            ),
            'Invoice recorded'
        );
    }
}
