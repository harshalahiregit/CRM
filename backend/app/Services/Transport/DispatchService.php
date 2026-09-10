<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Services\Transport\Contracts\FleetResourceGateway;
use App\Support\Transport\DispatchScope;
use App\Support\Transport\TripStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dispatch confirmation — RTM STOS-REQ-OPS-008, FRS TRP-P0-006.
 *
 * Authorised by the owner on 2026-09-10 in place of a ticket; see
 * DispatchScope::AUTHORIZATION and D-18.
 *
 * ── WHAT "FREEZE" MEANS HERE ──────────────────────────────────────────────
 * TRP-P0-006: "Freeze key dispatch fields after release; changes create
 * version." Two behaviours, and the distinction is the whole design:
 *
 *   BEFORE release   the five fields are ordinary editable trip fields.
 *   AT release       they are snapshotted, version becomes 1, and the plain
 *                    update path stops accepting them.
 *   AFTER release    a change is REFUSED unless it comes through amend(),
 *                    which demands a reason, bumps the version and writes the
 *                    before/after pair to the audit trail.
 *
 * So "frozen" does not mean immutable — TRP-P0-006 explicitly anticipates
 * changes. It means a change can no longer happen quietly.
 *
 * ── WHERE THE VERSIONS LIVE ───────────────────────────────────────────────
 * TRP-P0-006's Audit column is, in full, "Version history". The transport audit
 * log already stores old_values and new_values with an actor and a timestamp
 * per change, which IS a version history — so `dispatch_version` is a counter
 * on the trip and the contents of each version are reconstructed from the
 * trail. A separate versions table would duplicate the audit log and add a
 * second unregistered entity on top of an already unticketed scope.
 *
 * ── THE FLEET BOUNDARY ────────────────────────────────────────────────────
 * BRW-050 wants Vehicle = In Operation and Driver = On Trip. Those are
 * Developer A's tables and the owner ruled Trip side must not write them.
 * Everything goes through FleetResourceGateway, whose shipped implementation
 * records intent and does nothing. A gateway that declines is NOT an error:
 * refusing a real dispatch over a bookkeeping mismatch would be worse than the
 * mismatch. The outcome is recorded either way.
 */
class DispatchService
{
    public function __construct(
        private PretripService $pretrip,
        private FleetResourceGateway $fleet,
    ) {
    }

    /** The five fields TRP-P0-006 freezes. */
    public const DISPATCH_FIELDS = [
        'planned_departure_at', 'planned_arrival_at',
        'pickup_contact', 'dispatch_destination', 'dispatch_instructions',
    ];

    /**
     * Confirm dispatch — pretrip_ok → dispatched.
     *
     * @param  array<string,mixed>  $fields  any of DISPATCH_FIELDS
     */
    public function confirm(TransportTrip $trip, array $fields, int $tenantId, ?User $actor = null): TransportTrip
    {
        $this->assertTenant($trip, $tenantId);
        $this->assertDispatchable($trip, $tenantId);

        $payload = $this->onlyDispatchFields($fields);

        return DB::transaction(function () use ($trip, $payload, $tenantId, $actor) {
            $from = $trip->status;

            $trip->forceFill(array_merge($payload, [
                'status'           => TripStatus::DISPATCHED,
                'dispatched_at'    => now(),
                'dispatched_by'    => $actor?->id,
                'dispatch_version' => 1,
                'updated_by'       => $actor?->id,
            ]))->save();

            // BRW-050, through the seam. Never throws; the answer is recorded.
            $applied = $this->fleet->markDispatched(
                $trip, $trip->vehicle_id, $trip->driver_id, $tenantId, $actor,
            );

            $assignment = TripAssignment::forTenant($tenantId)->forTrip($trip->id)->active()->first();

            $trip->auditTransition(
                'transport.trip.status_changed',
                $from,
                TripStatus::DISPATCHED,
                $actor,
                [
                    'rule'          => DispatchScope::OPS_008,
                    'transition'    => DispatchScope::STATE_EDGE_OWNED,
                    'registry'      => DispatchScope::STT_005.' (destination only — precondition landed on allocated->pretrip_ok)',
                    'authorization' => DispatchScope::AUTHORIZATION,
                    'sources'       => 'RTM STOS-REQ-OPS-008; FRS TRP-P0-006; BRW-050; SM-TRP',
                    'trip_number'   => $trip->trip_number,
                    'version'       => 1,
                    'dispatch'      => $this->snapshot($trip),
                    'crew'          => [
                        'vehicle_id' => $assignment?->vehicle_id,
                        'driver_id'  => $assignment?->driver_id,
                    ],
                    // BRW-050's side effects, and what actually happened to each.
                    'fleet_state_applied' => $applied,
                    'fleet_boundary'      => $applied ? null : 'pending Developer A gateway — vehicle/driver state NOT updated',
                    'deferred_effects'    => array_keys(array_filter(
                        DispatchScope::BRW_050_DISPOSITION,
                        fn (string $d) => $d !== 'built',
                    )),
                ],
            );

            Log::channel('transport')->info('Trip dispatched', [
                'trip_id' => $trip->id, 'tenant_id' => $tenantId,
                'user_id' => $actor?->id, 'fleet_state_applied' => $applied,
            ]);

            return $trip->fresh();
        });
    }

    /**
     * Amend a frozen dispatch field — TRP-P0-006's "changes create version".
     *
     * A reason is required. TRP-P0-006 also wants "Change approval after
     * release"; no approval entity exists in Step 11 and every other approval in
     * this package is P1, so the change is versioned and audited but not gated.
     * Recorded in DispatchScope::EXCLUDED.
     *
     * @param  array<string,mixed>  $fields
     */
    public function amend(TransportTrip $trip, array $fields, string $reason, int $tenantId, ?User $actor = null): TransportTrip
    {
        $this->assertTenant($trip, $tenantId);

        if ($trip->dispatch_version < 1) {
            throw new BusinessException(
                'This trip has not been dispatched yet, so there is nothing to amend. '
                .'Confirm dispatch first.',
                422,
            );
        }

        if (trim($reason) === '') {
            throw new BusinessException(
                'A dispatch field is frozen once the trip is released. Give a reason for the change.',
                422,
            );
        }

        $payload = $this->onlyDispatchFields($fields);
        if ($payload === []) {
            throw new BusinessException('No dispatch field was supplied to amend.', 422);
        }

        $before = $this->snapshot($trip);
        $changed = array_keys(array_filter(
            $payload,
            fn ($v, $k) => ($before[$k] ?? null) !== $this->normalise($k, $v),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed === []) {
            // Nothing actually differs. Writing a version for a no-op would
            // inflate the history and make a real amendment harder to find.
            return $trip->fresh();
        }

        return DB::transaction(function () use ($trip, $payload, $reason, $before, $changed, $tenantId, $actor) {
            $version = ((int) $trip->dispatch_version) + 1;

            $trip->forceFill(array_merge($payload, [
                'dispatch_version' => $version,
                'updated_by'       => $actor?->id,
            ]))->save();

            $trip->audit('transport.dispatch.amended', $actor,
                old: array_intersect_key($before, array_flip($changed)),
                new: array_intersect_key($this->snapshot($trip->fresh()), array_flip($changed)),
                context: [
                    'rule'        => 'FRS TRP-P0-006 — freeze after release; changes create version',
                    'trip_number' => $trip->trip_number,
                    'version'     => $version,
                    'reason'      => trim($reason),
                    'fields'      => $changed,
                    // Stated rather than implied, so nobody reads the absence of
                    // an approver as an approval.
                    'approval'    => null,
                    'approval_note' => 'TRP-P0-006 requires change approval after release; no approval entity exists (P1, deferred).',
                ],
            );

            Log::channel('transport')->info('Dispatch amended', [
                'trip_id' => $trip->id, 'version' => $version,
                'fields' => $changed, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $trip->fresh();
        });
    }

    /** The frozen five, as stored. Used for audit snapshots and comparison. */
    public function snapshot(TransportTrip $trip): array
    {
        $out = [];
        foreach (self::DISPATCH_FIELDS as $f) {
            $out[$f] = $this->normalise($f, $trip->{$f});
        }

        return $out;
    }

    /** Is this trip frozen? True once dispatch has been confirmed. */
    public function isFrozen(TransportTrip $trip): bool
    {
        return (int) $trip->dispatch_version >= 1;
    }

    /* ── internals ───────────────────────────────────────────────────── */

    /**
     * BRW-046 / SM-TRP's entry gate for `dispatched`, which is "Pre-trip
     * passed" — and that is exactly the state the trip must already be in.
     *
     * The pre-trip checklist is NOT re-evaluated here. SNG-TRN-010 already
     * enforced it to reach pretrip_ok, and CMP §159 requires the gate to answer
     * deterministically; re-running it would let a trip that legitimately passed
     * be refused at the last step with nothing on screen to explain why.
     *
     * That is a deliberate trade recorded in D-18: whoever revisits dispatch
     * must decide whether readiness should be re-validated at departure, since
     * a document can lapse between passing pre-trip and leaving the yard.
     */
    private function assertDispatchable(TransportTrip $trip, int $tenantId): void
    {
        if (TripStatus::canTransition($trip->status, TripStatus::DISPATCHED)) {
            return;
        }

        if ($trip->status === TripStatus::DISPATCHED) {
            throw new BusinessException('This trip has already been dispatched.', 422);
        }

        // BRW-048 — "If dispatch fails, Sangoe must display exact reason."
        throw new BusinessException(
            'A trip that is '.TripStatus::label($trip->status).' cannot be dispatched. '
            .'It must pass its pre-trip checks first.',
            422,
        );
    }

    /** @param array<string,mixed> $fields */
    private function onlyDispatchFields(array $fields): array
    {
        return array_intersect_key($fields, array_flip(self::DISPATCH_FIELDS));
    }

    /** Dates compare as strings, so a Carbon and its ISO form are one value. */
    private function normalise(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, ['planned_departure_at', 'planned_arrival_at'], true)) {
            return $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : (string) \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i:s');
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function assertTenant(TransportTrip $trip, int $tenantId): void
    {
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new \App\Exceptions\ResourceNotFoundException('Trip');
        }
    }
}
