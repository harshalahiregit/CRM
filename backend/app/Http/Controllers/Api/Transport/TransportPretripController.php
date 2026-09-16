<?php

namespace App\Http\Controllers\Api\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\CompletePretripCheckRequest;
use App\Http\Requests\Transport\SubmitPretripChecksRequest;
use App\Models\Transport\TripPretripCheck;
use App\Services\Transport\PretripService;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportTripService;
use App\Support\Transport\TripStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pre-trip check endpoints — SNG-TRN-010 step 7.
 *
 *   GET   /transport/trips/{trip}/prechecks           readiness      (no registry row)
 *   POST  /transport/trips/{trip}/prechecks           generate       Step 5's path
 *   PATCH /transport/trips/{trip}/prechecks/{check}   confirm one    (no registry row)
 *   PATCH /transport/trips/{trip}/pass-pretrip        the gate       (no registry row)
 *
 * ── ONE OF THESE FOUR IS SPECIFIED ────────────────────────────────────────
 * Step 5's API sheet gives exactly `POST .../prechecks` with request
 * `check_items,evidence` and response `checklist_id,result,state`. Step 11's
 * LOCKED API registry (API-001…015) has no pre-trip endpoint at all, and
 * neither does Step 4's parallel list — recorded as D-15. The same situation
 * ticket 009 met with candidates and release (D-12), one ticket later and
 * worse, because there the anchor endpoint at least existed.
 *
 * The three additions follow the conventions this module already set rather
 * than inventing new ones: the collection path answers GET for reading, PATCH
 * changes one field of one existing record, and a state transition is a PATCH
 * on a named verb — exactly as PATCH /trips/{id}/submit-viability already does
 * for STT-001.
 *
 * ── WHAT `checklist_id` BECAME ────────────────────────────────────────────
 * Step 5's response names it, implying a header row. There is no header table:
 * readiness is DERIVED from the check rows every time it is asked for, because
 * a stored status is wrong the morning after a document lapses. The response
 * therefore carries `trip_id` plus the derived `status` in place of a
 * `checklist_id` that would identify a record that does not exist. Recorded
 * with D-15.
 *
 * ── THE ANSWER ALWAYS EXPLAINS ITSELF ─────────────────────────────────────
 * Every response — success, refusal, and the plain GET — carries the full
 * checks array AND `blocking_message`, the same sentence the gate would refuse
 * with. UX §35 is explicit that a screen must never merely show Blocked, and a
 * client that had to POST the gate just to learn why it could not would either
 * skip the call or render a bare "failed".
 *
 * ── GATING ────────────────────────────────────────────────────────────────
 * Reads take transport.pretrip.view; the three writes take
 * transport.pretrip.perform. Neither key is in Step 11's Permissions sheet, and
 * the three documents that name an actor name three different ones, none of
 * which is "Supervisor" — see TransportPermission and defect D-21.
 *
 * Thin, like every other transport controller: no queries beyond resolving the
 * trip, no rules. Tenancy is resolved once by TransportTripService::find(),
 * which raises ResourceNotFoundException — 404, never 403 — and again inside
 * every PretripService call.
 */
class TransportPretripController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PretripService $pretrip,
        private TransportTripService $trips,
        private TransportAuditLogger $audit,
    ) {
    }

    /**
     * The trip's readiness, as it stands.
     *
     * A pure read: it never generates. A GET that quietly created rows would
     * make a dispatcher's page refresh an act with side effects, and would let a
     * user with only `pretrip.view` write to the database.
     */
    public function index(Request $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);   // 404, never 403

        return $this->success(
            $this->pretrip->readiness($record, $tenantId),
            'Pre-trip readiness retrieved',
        );
    }

    /**
     * Build or refresh the checklist — RTM STOS-REQ-OPS-004.
     *
     * Step 5's path and its `check_items`. Generation runs first and always:
     * confirming an item against a stale evaluation is the one thing this
     * endpoint must not allow, so the reconciliation happens before any
     * submitted confirmation is applied.
     */
    public function store(SubmitPretripChecksRequest $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);
        $items    = $request->validated()['check_items'] ?? [];

        try {
            $this->pretrip->generate($record, $tenantId, $request->user());

            if ($items !== []) {
                $this->pretrip->completeMany($record, $items, $tenantId, $request->user());
            }
        } catch (ResourceNotFoundException $e) {
            // MUST precede the BusinessException arm: ResourceNotFoundException
            // EXTENDS it, so a broad catch would swallow a 404 and answer 422 —
            // and a 422 on another tenant's id confirms the record exists.
            throw $e;
        } catch (BusinessException $e) {
            return $this->refusal($e, $record, $tenantId);
        }

        $readiness = $this->pretrip->readiness($record->fresh(), $tenantId);

        return $this->success($readiness, $items === []
            ? 'Pre-trip checklist ready'
            : 'Pre-trip checks recorded');
    }

    /**
     * Confirm ONE check — the acceptance criterion, per item.
     *
     * "Checklist completion is time/user stamped" is the whole of this ticket's
     * stated AC, and this is where a person supplies the stamp.
     */
    public function complete(CompletePretripCheckRequest $request, int $trip, int $check): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);

        // Scoped by tenant AND by trip. A check id that belongs to another trip,
        // or another tenant, reads as "no such check" — never as a 422 that would
        // confirm it exists somewhere.
        $row = TripPretripCheck::forTenant($tenantId)->forTrip($record->id)->find($check);

        if ($row === null) {
            throw new ResourceNotFoundException('Pre-trip check');
        }

        try {
            $completed = $this->pretrip->complete(
                $row, $tenantId, $request->user(), $request->validated()['remarks'] ?? null,
            );
        } catch (BusinessException $e) {
            return $this->refusal($e, $record, $tenantId);
        }

        return $this->success(array_merge(
            $this->pretrip->readiness($record->fresh(), $tenantId),
            ['check' => $completed, 'audit' => $this->audit->forSubject($completed, $tenantId)],
        ), 'Pre-trip check confirmed');
    }

    /**
     * The gate — allocated → pretrip_ok.
     *
     * STT-005's precondition work under the Q1 ruling of 2026-09-09. This
     * endpoint does NOT dispatch: `pretrip_ok → dispatched` is dispatch
     * confirmation, which needs five fields that do not exist and belongs to no
     * ticket in the register (D-18). Nothing here writes `dispatched`, and the
     * response says which state was actually reached so no client can assume
     * otherwise.
     */
    public function pass(Request $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);

        try {
            $moved = $this->pretrip->passPretrip($record, $tenantId, $request->user());
        } catch (BusinessException $e) {
            // BRW-046, BRW-052, OPS §30, CMP §159 — a block is a verdict, not an
            // error, so the body carries the same checks a success would.
            return $this->refusal($e, $record, $tenantId);
        }

        return $this->success([
            'trip'      => $moved,
            'readiness' => $this->pretrip->readiness($moved, $tenantId),
            // Stated rather than implied: a client must not read "pre-trip
            // passed" as "dispatched".
            'pretrip_ok' => $moved->status === TripStatus::PRETRIP_OK,
            'dispatched' => false,
            'audit'      => $this->audit->forSubject($moved, $tenantId),
        ], 'Pre-trip checks passed — the trip is ready for dispatch');
    }

    /**
     * One refusal shape for all three writes.
     *
     * Same discipline as TransportAllocationController: the status code carries
     * the outcome, the body carries the reasoning, and the reasoning is the same
     * structure a success returns, so a client renders one component either way.
     */
    private function refusal(BusinessException $e, $record, int $tenantId): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $e->getMessage(),
            'data'    => $this->pretrip->readiness($record->fresh(), $tenantId),
        // getStatusCode(), not getCode(): BusinessException carries its status in
        // its own property and getCode() is always 0.
        ], $e->getStatusCode());
    }
}
