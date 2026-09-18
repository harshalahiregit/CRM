<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripEvent;
use App\Models\User;
use App\Support\Transport\TripEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The one way anything writes to the trip timeline.
 *
 * ── THIS IS THE CONTRACT BETWEEN THREE SECTIONS ─────────────────────────
 * CTD §32: "Timeline must combine events from all connected systems." Of CTD
 * §31's sixteen example entries, seven belong to Person 2 or Person 3 — GPS
 * Active, Genset ON, Port Entry, Port Exit, Documents Handed Over, POD
 * Uploaded, Billing Ready, Feedback. This service is how they contribute
 * without either of them reaching into P1's audit trail or P1's models.
 *
 *   app(TripEventRecorder::class)->record(
 *       type:  'genset.on',
 *       trip:  $trip,                    // or tripId: 41
 *       actor: $user,                    // null for a machine
 *       detail: ['reading_c' => -18.2],
 *       occurredAt: $ping->recorded_at,  // when it HAPPENED, not now
 *   );
 *
 * Category and source are looked up from TripEventType, so a caller does not
 * have to know CTD §101's nine branches or §33's ten sources — it names the
 * event and the registry supplies the rest.
 *
 * ── IN-PROCESS ONLY, DELIBERATELY ───────────────────────────────────────
 * There is no HTTP write endpoint. Nobody has asked for one, and an HTTP write
 * surface is a separate decision with its own questions — authentication for a
 * device, idempotency for a retried batch, rate limiting for a chatty sensor.
 * Adding one silently now would answer all three by accident.
 *
 * ── AND IT NEVER THROWS INTO THE CALLER ─────────────────────────────────
 * A timeline entry is a record OF work, not part of it. A GPS ingest that
 * succeeds must not be rolled back because its event row failed, and a
 * dispatch must not fail because a timeline write did. So a failure is logged
 * and swallowed, and the caller gets null.
 *
 * That is a deliberate asymmetry with transport_audit_logs, which DOES throw:
 * the audit trail is the compliance record and losing one silently is a
 * defect. Losing a timeline entry is a gap in a story.
 */
class TripEventRecorder
{
    /**
     * Append one event.
     *
     * @param  string  $type  a key from TripEventType::REGISTRY — or any string;
     *                        an unregistered one is recorded and warned about
     *                        rather than refused, because refusing would make
     *                        P2 and P3 wait on a P1 release to record anything
     *                        new. TripEventRegistryTest is what catches drift.
     * @param  array<string,mixed>|null  $detail
     */
    public function record(
        string $type,
        ?TransportTrip $trip = null,
        ?User $actor = null,
        ?array $detail = null,
        ?string $summary = null,
        Carbon|string|null $occurredAt = null,
        ?int $tenantId = null,
        ?int $tripId = null,
        ?int $orderId = null,
        ?int $consignmentId = null,
        ?int $containerId = null,
        ?string $category = null,
        ?string $source = null,
        ?int $correctsEventId = null,
    ): ?TripEvent {
        try {
            $tenant = $tenantId ?? $trip?->tenant_id ?? $actor?->tenant_id;

            if (! $tenant) {
                // Nothing is tenant-less in this module, and a row that cannot
                // say whose it is would be invisible to every scoped read.
                throw new \InvalidArgumentException('A trip event needs a tenant.');
            }

            $occurred = $occurredAt
                ? ($occurredAt instanceof Carbon ? $occurredAt : Carbon::parse($occurredAt))
                : now();

            if (! TripEventType::isKnown($type)) {
                // Warned, not refused. The registry is a shared file anyone may
                // append to; this line is how the gap reaches somebody.
                Log::channel('transport')->warning('Unregistered trip event type', [
                    'event_type' => $type, 'tenant_id' => $tenant,
                    'hint' => 'Add it to TripEventType::REGISTRY with its source line, and tell the group.',
                ]);
            }

            return TripEvent::create([
                'tenant_id'      => $tenant,
                'trip_id'        => $tripId ?? $trip?->id,
                // Carried from the trip so a container's timeline needs no join.
                'order_id'       => $orderId ?? $trip?->order_id,
                'consignment_id' => $consignmentId ?? $trip?->consignment_id,
                'container_id'   => $containerId,
                'event_type'     => $type,
                'category'       => $category ?? TripEventType::categoryFor($type) ?? TripEventType::OPERATIONAL,
                'source'         => $source ?? TripEventType::sourceFor($type) ?? TripEventType::SYSTEM,
                'occurred_at'    => $occurred,
                'recorded_at'    => now(),
                'summary'        => $summary ?? TripEventType::label($type),
                'detail'         => $detail,
                'actor_id'       => $actor?->id,
                'actor_name'     => $actor?->name,
                'actor_role'     => $actor?->role,
                'created_by'     => $actor?->id,
                // CTD §34, set on the way in — the model refuses `updating`, so
                // a correction cannot be linked up afterwards.
                'corrects_event_id' => $correctsEventId,
            ]);
        } catch (\Throwable $e) {
            // See the class docblock: a timeline entry is a record OF work, not
            // part of it, and must never take the work down with it.
            Log::channel('transport')->error('Trip event could not be recorded', [
                'event_type' => $type, 'trip_id' => $tripId ?? $trip?->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * CTD §34 — correct an earlier event by appending, never by editing.
     *
     * "Historical events should not be silently edited. Corrections should
     * create a Correction Event with audit trail." Both rows stay readable, so
     * the correction is itself part of the history rather than a quiet
     * overwrite of it.
     */
    public function correct(TripEvent $original, string $summary, ?User $actor = null, ?array $detail = null): ?TripEvent
    {
        return $this->record(
            type: 'event.corrected',
            actor: $actor,
            detail: [
                'corrects'          => $original->event_type,
                'corrected_summary' => $original->summary,
                ...($detail ?? []),
            ],
            summary: $summary,
            tenantId: (int) $original->tenant_id,
            tripId: $original->trip_id,
            orderId: $original->order_id,
            consignmentId: $original->consignment_id,
            containerId: $original->container_id,
            correctsEventId: $original->id,
        );
    }
}
