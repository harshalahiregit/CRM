<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Services\Transport\TripEventRecorder;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Services\Transport\Contracts\FleetResourceGateway;
use App\Support\Transport\DispatchScope;
use App\Support\Transport\TransitScope;
use App\Support\Transport\TripStatus;
use Illuminate\Support\Carbon;
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
 * Person 2's tables and the owner ruled Trip side must not write them.
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
                    'fleet_boundary'      => $applied ? null : 'pending Person 2 gateway — vehicle/driver state NOT updated',
                    'deferred_effects'    => array_keys(array_filter(
                        DispatchScope::BRW_050_DISPOSITION,
                        fn (string $d) => $d !== 'built',
                    )),
                ],
            );

            app(TripEventRecorder::class)->record('trip.dispatched', trip: $trip, actor: $actor);

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


    /**
     * TRP-P0-006's "Version history", reconstructed from the audit trail.
     *
     * The class docblock explains why there is no versions table: the audit log
     * already stores an actor, a timestamp and a before/after pair per change,
     * which IS a version history. What was missing was a way to READ it as one —
     * the acceptance criterion asks for the history, not merely for the data it
     * could be built from.
     *
     * Version 1 is the release itself and has no "before"; every later version
     * is one amendment, with the fields it touched and the reason it was given.
     * Ordered oldest-first, because a history is read forwards.
     *
     * Only the two dispatch actions are read. The trip's trail also carries
     * allocation and pre-trip entries, and folding those in would answer a
     * question nobody asked here.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(TransportTrip $trip, int $tenantId): array
    {
        $this->assertTenant($trip, $tenantId);

        if (! $trip->isDispatched()) {
            return [];
        }

        $entries = TransportAuditLog::forTenant($tenantId)
            ->forSubject($trip::class, (int) $trip->id)
            ->whereIn('action', ['transport.trip.status_changed', 'transport.dispatch.amended'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($entries as $entry) {
            $context = $entry->context ?? [];

            if ($entry->action === 'transport.trip.status_changed') {
                // The trail holds every status change this trip ever made; only
                // the one that reached `dispatched` opened version 1.
                if (($context['transition'] ?? null) !== DispatchScope::STATE_EDGE_OWNED) {
                    continue;
                }

                $out[] = [
                    'version'     => (int) ($context['version'] ?? 1),
                    'type'        => 'release',
                    'occurred_at' => $entry->occurred_at?->toIso8601String(),
                    'actor_id'    => $entry->actor_id,
                    'actor_name'  => $entry->actor_name,
                    'reason'      => null,
                    'fields'      => array_keys($context['dispatch'] ?? []),
                    'before'      => null,
                    'after'       => $context['dispatch'] ?? null,
                    // Surfaced rather than left in the audit blob: a reader of the
                    // history is exactly the person who needs to know the fleet
                    // side did not move. See FleetResourceGateway.
                    'fleet_state_applied' => $context['fleet_state_applied'] ?? null,
                    'fleet_boundary'      => $context['fleet_boundary'] ?? null,
                ];

                continue;
            }

            $out[] = [
                'version'     => (int) ($context['version'] ?? 0),
                'type'        => 'amendment',
                'occurred_at' => $entry->occurred_at?->toIso8601String(),
                'actor_id'    => $entry->actor_id,
                'actor_name'  => $entry->actor_name,
                'reason'      => $context['reason'] ?? null,
                'fields'      => $context['fields'] ?? array_keys($entry->new_values ?? []),
                'before'      => $entry->old_values,
                'after'       => $entry->new_values,
                // Stated on every amendment, so the absence of an approver is
                // never read as an approval. TRP-P0-006 wants change approval
                // after release; no approval entity exists (P1, deferred).
                'approval'      => $context['approval'] ?? null,
                'approval_note' => $context['approval_note'] ?? null,
            ];
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
     * BRW-046's gate, in two parts.
     *
     * FIRST the state: SM-TRP's entry gate for `dispatched` is "Pre-trip
     * passed", and that is exactly the state the trip must already be in.
     *
     * THEN the facts, re-derived. BRW-046 reads "Vehicle cannot dispatch until
     * all mandatory dispatch checks pass" — a statement about the moment of
     * departure, not about the moment the checklist was confirmed. Between the
     * two, a licence expires, a permit lapses, an insurance policy runs out. The
     * stored row still says `pass`, because that is what was true when it was
     * written. Owner's ruling, 2026-09-10: re-derive at dispatch.
     *
     * ── WHY THIS DOES NOT CONTRADICT CMP §159 ─────────────────────────────
     * §159 requires the dispatch answer to be DETERMINISTIC, not to be cached.
     * Re-deriving from the same live sources yields the same answer for the same
     * facts; what changes the verdict is the facts changing, which is the whole
     * point. The refusal names the check, what it says now and what it said
     * before, so a screen that showed READY a moment ago can explain itself
     * (BRW-048, UX §35) rather than leaving a dispatcher staring at a refusal
     * with no cause.
     *
     * PretripService::revalidate() writes nothing — see its docblock. A refused
     * dispatch leaves the trip exactly as it found it, at `pretrip_ok`, so the
     * fix-and-retry path stays open.
     */
    private function assertDispatchable(TransportTrip $trip, int $tenantId): void
    {
        if ($trip->status === TripStatus::DISPATCHED) {
            throw new BusinessException('This trip has already been dispatched.', 422);
        }

        if (! TripStatus::canTransition($trip->status, TripStatus::DISPATCHED)) {
            // BRW-048 — "If dispatch fails, Sangoe must display exact reason."
            throw new BusinessException(
                'A trip that is '.TripStatus::label($trip->status).' cannot be dispatched. '
                .'It must pass its pre-trip checks first.',
                422,
            );
        }

        $live = $this->pretrip->revalidate($trip, $tenantId);

        if ($live['ready']) {
            return;
        }

        // Named separately from the generic blockers because the two are
        // different problems for the dispatcher. A blocker that was always there
        // means the checklist was never satisfied; a LAPSED one means it was
        // satisfied and has since stopped being true, and only the second
        // explains why a screen that read READY is now refusing.
        if ($live['lapsed'] !== []) {
            $lapsed = implode(' ', array_map(
                fn (array $l) => $l['label'].': '.($l['detail'] ?? 'no longer passes')
                    .' (it passed when the checklist was confirmed).',
                $live['lapsed'],
            ));

            throw new BusinessException(
                'Dispatch blocked — this trip passed its pre-trip checks, but something has changed since. '
                .$lapsed.' Resolve it and re-run the checklist before dispatching.',
                422,
            );
        }

        throw new BusinessException($live['message'] ?? 'Dispatch blocked — pre-trip readiness could not be confirmed.', 422);
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

    /**
     * STT-006 — `dispatched → in_transit`. Record that the vehicle actually left.
     *
     *   STT-006 | trigger "Dispatch vehicle" | actor TripEngine
     *           | precondition "Dispatch confirmed" | side effect "Start
     *             monitoring" | audited | LOCKED
     *   SM-TRP  | `in_transit` · active · entry gate "Departure recorded"
     *           | exit "Delivered/Exception" · owner Operations
     *   RTM     | STOS-REQ-OPS-009 "Track trip status" · P0
     *
     * ── AUTHORISED 2026-09-10 AND UNBUILT FOR A WEEK — D-105 ─────────────
     * The owner's Q3 ruling wired this edge as a manual "Record departure" with
     * `departed_at` and `departed_by` only. The columns and their index shipped
     * that day and nothing ever wrote them, while four documents went on saying
     * the edge was blocked. Found by re-reading the ruling, not the comments.
     *
     * ── WHY IT SITS IN DispatchService AND NOT IN THE TRIP SERVICE ───────
     * "Dispatch confirmed" is this class's own precondition — nothing else
     * knows whether a trip was released, by whom, or with which frozen fields.
     * Departure is the second half of the dispatcher's act, and putting it
     * anywhere else would mean re-deriving dispatch state from outside.
     *
     * ── WHAT IT DOES NOT DO ──────────────────────────────────────────────
     * "Start monitoring" starts nothing. There is no GPS (SNG-TRN-020, P1), no
     * reefer model, and `trip_exceptions` has a schema but no model. The
     * deferred effects are recorded ON THE AUDIT ROW rather than implied by
     * silence, so a reader six months from now can see what was not started.
     *
     * NOT re-validated against pre-trip. `assertDispatchable()` re-derives
     * BRW-046 at RELEASE, which is the moment the rule is about. Re-running it
     * here would mean a licence expiring while the truck is loading could strand
     * a vehicle that has already been released — a block with no remedy, since
     * the trip cannot go back either. Departure records a fact that has already
     * happened; it does not grant permission.
     *
     * @param  array{departed_at?:string|null}  $fields
     */
    public function recordDeparture(TransportTrip $trip, array $fields, int $tenantId, ?User $actor = null): TransportTrip
    {
        $this->assertTenant($trip, $tenantId);

        $from = $trip->status;
        $to   = TripStatus::IN_TRANSIT;

        if (! TripStatus::canTransition($from, $to)) {
            throw new BusinessException(
                $from === TripStatus::IN_TRANSIT
                    ? 'This trip is already on the road — it left '.$trip->departed_at?->diffForHumans().'.'
                    : 'Only a dispatched trip can be recorded as departed. This trip is '.$trip->statusLabel().'.',
                422
            );
        }

        $departedAt = $this->departureTime($trip, $fields['departed_at'] ?? null);

        return DB::transaction(function () use ($trip, $from, $to, $departedAt, $tenantId, $actor) {
            $trip->forceFill([
                'status'      => $to,
                'departed_at' => $departedAt,
                'departed_by' => $actor?->id,
                'updated_by'  => $actor?->id,
            ])->save();

            $assignment = TripAssignment::forTenant($tenantId)->forTrip($trip->id)->active()->first();

            // STT-006's other half, which nothing called — P2's handover, item
            // (b). markDispatched() leaves the vehicle ALLOCATED, because
            // dispatch is not departure; markDeparted() is what moves it to
            // IN_TRANSIT. With no caller, a truck that had left the yard stayed
            // ALLOCATED for the whole journey and only closure released it.
            //
            // Inside the transaction and after the trip's own write, so Fleet
            // cannot move a vehicle for a departure that then rolls back. It
            // never throws and reports false rather than refusing — a loaded
            // truck does not wait in a yard over bookkeeping — so the outcome
            // is recorded below, not acted on.
            $departureApplied = $this->fleet->markDeparted(
                $trip, $assignment?->vehicle_id, $tenantId, $actor,
            );

            $trip->auditTransition(
                'transport.trip.status_changed',
                $from,
                $to,
                $actor,
                [
                    'rule'          => TransitScope::OPS_009,
                    'transition'    => TransitScope::EDGE_DEPARTURE,
                    'registry'      => TransitScope::STT_006,
                    'authorization' => TransitScope::AUTHORIZATION_DEPARTURE,
                    'sources'       => 'RTM STOS-REQ-OPS-009; FRS TRP-P0-011 ("manual update fallback"); SM-TRP entry gate "Departure recorded"',
                    'trip_number'   => $trip->trip_number,
                    'departed_at'   => $departedAt,
                    // Recorded, not implied: a manual departure is a fallback
                    // for telemetry that does not exist, and the audit says so.
                    'recorded'      => 'manual',
                    'crew'          => [
                        'vehicle_id' => $assignment?->vehicle_id,
                        'driver_id'  => $assignment?->driver_id,
                    ],
                    // STT-006's side effect, and what actually happened to it.
                    'monitoring_started' => false,
                    // Whether Fleet actually moved the vehicle to IN_TRANSIT.
                    'fleet_departure_applied' => $departureApplied,
                    'deferred_effects'   => array_keys(array_filter(
                        TransitScope::SIDE_EFFECTS,
                        fn (string $d) => $d !== 'built',
                    )),
                ],
            );

            app(TripEventRecorder::class)->record(
                'trip.departed', trip: $trip, actor: $actor, occurredAt: $departedAt,
            );

            Log::channel('transport')->info('Trip departed', [
                'trip_id' => $trip->id, 'tenant_id' => $tenantId,
                'user_id' => $actor?->id, 'departed_at' => $departedAt,
                // D-105 in the logs as well as the code, so the first live
                // departure is traceable to the ruling that authorised it.
                'registry' => TransitScope::STT_006, 'recorded' => 'manual',
            ]);

            return $trip->fresh();
        });
    }

    /**
     * When the trip left — TransitScope::TIME_ORDER.
     *
     * BACKDATING IS THE NORMAL CASE and is allowed. A dispatcher records at
     * 11:00 that the truck left at 09:30; refusing that would teach people to
     * enter the wrong time rather than the right one, which is worse than the
     * imprecision it prevents.
     *
     * Two things are refused, because each asserts something false:
     *   - a departure in the FUTURE — it has not happened;
     *   - a departure BEFORE `dispatched_at` — the vehicle left before it was
     *     released, which would make the dispatch record meaningless.
     */
    private function departureTime(TransportTrip $trip, ?string $given): string
    {
        if ($given === null || trim($given) === '') {
            return now()->format('Y-m-d H:i:s');
        }

        $at = Carbon::parse($given);

        if ($at->isFuture()) {
            throw new BusinessException(
                'A departure cannot be recorded in the future. Leave the time blank to use now.',
                422
            );
        }

        if ($trip->dispatched_at && $at->lt($trip->dispatched_at)) {
            throw new BusinessException(
                'The trip cannot have left before it was released. It was released on '
                .$trip->dispatched_at->format('j M Y, H:i').'.',
                422
            );
        }

        return $at->format('Y-m-d H:i:s');
    }

    private function assertTenant(TransportTrip $trip, int $tenantId): void
    {
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new \App\Exceptions\ResourceNotFoundException('Trip');
        }
    }
}
