<?php

namespace App\Console\Commands;

use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripEvent;
use App\Support\Transport\TripEventType;
use App\Support\Transport\TripStatus;
use Illuminate\Console\Command;

/**
 * Give trips that predate `trip_events` a timeline — the Q3 ruling.
 *
 * ── WHY A BACKFILL AND NOT A UNION ──────────────────────────────────────
 * Container 360's timeline read `transport_audit_logs` until now. Three options
 * were on the table: replace it, union both forever, or replace it with a
 * backfill. The source decided it — CTD §32 calls the timeline the thing that
 * "must combine events from all connected systems" and §101 makes it one
 * chronological stream. A "single source of truth" that is two sources is the
 * thing those sections argue against.
 *
 * So the audit log stays exactly where it is, as the compliance record of what
 * WE changed, and this command gives the events table the history it never saw.
 *
 * ── IDEMPOTENT, AND THAT IS THE WHOLE DESIGN ────────────────────────────
 * Every row it writes carries `detail.backfilled_from_audit_id`. A second run
 * skips anything already carried across, so it can be run on a database that is
 * half done, or run twice by two people, without producing a duplicate
 * timeline. Re-runnable beats reversible when the table is append-only.
 */
class BackfillTripEvents extends Command
{
    protected $signature = 'stos:backfill-trip-events
                            {--tenant= : one workspace only}
                            {--pretend : count what would be written and write nothing}';

    protected $description = 'Build trip_events from the existing transport audit trail (idempotent)';

    /** Audit actions that describe something that HAPPENED, and their type. */
    private const MAP = [
        'transport.trip.created'              => 'trip.created',
        'transport.container.created'         => 'container.created',
        'transport.consignment.created'       => 'consignment.created',
        'transport.trip.assignment_created'   => 'vehicle.allocated',
        'transport.trip.assignment_released'  => 'crew.released',
        'transport.dispatch.confirmed'        => 'trip.dispatched',
        'transport.container.attached'        => 'container.attached',
        'transport.container.detached'        => 'container.detached',
        'transport.exception.raised'          => 'exception.raised',
    ];

    /**
     * A status change becomes the event for the state it ARRIVED at.
     *
     * `allocated` is deliberately ABSENT. The assignment row above already
     * carries that moment, and mapping both produced "Vehicle allocated" twice
     * in a row on the timeline — found by reading the rebuilt screen, not by
     * reading this map.
     */
    private const BY_STATUS = [
        TripStatus::VIABILITY_PENDING  => 'trip.submitted',
        TripStatus::APPROVED           => 'trip.approved',
        TripStatus::DRAFT              => 'trip.returned',
        TripStatus::PRETRIP_OK         => 'pretrip.passed',
        TripStatus::DISPATCHED         => 'trip.dispatched',
        TripStatus::IN_TRANSIT         => 'trip.departed',
        TripStatus::DELIVERED          => 'trip.delivered',
        TripStatus::POD_VERIFIED       => 'pod.verified',
        TripStatus::BILLABLE           => 'billing.ready',
        TripStatus::BILLED             => 'invoice.posted',
        TripStatus::COLLECTION_PENDING => 'collection.recorded',
        TripStatus::CLOSED             => 'trip.closed',
    ];

    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');
        $tenant  = $this->option('tenant');

        // Everything already carried across, so a second run is a no-op.
        $already = TripEvent::query()
            ->whereNotNull('detail')
            ->pluck('detail')
            ->map(fn ($d) => is_array($d) ? ($d['backfilled_from_audit_id'] ?? null) : null)
            ->filter()->flip();

        $written = 0;
        $skipped = 0;

        // Containers and consignments too, not only trips. The old audit-log
        // timeline showed "Container created" and the attachments, and a
        // container's passport would have lost them otherwise — a backfill that
        // drops history is not a backfill.
        TransportAuditLog::query()
            ->whereIn('auditable_type', [
                TransportTrip::class,
                \App\Models\Transport\TransportContainer::class,
                \App\Models\Transport\TransportConsignment::class,
            ])
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$written, &$skipped, $already, $pretend) {
                foreach ($rows as $log) {
                    if ($already->has($log->id)) { $skipped++; continue; }

                    $type = $this->typeFor($log);
                    if (! $type) { continue; }

                    if ($pretend) { $written++; continue; }

                    // Which column the subject id belongs in depends on what
                    // the audit row was about.
                    $isTrip = $log->auditable_type === TransportTrip::class;
                    $isContainer = $log->auditable_type === \App\Models\Transport\TransportContainer::class;
                    $trip = $isTrip ? TransportTrip::withTrashed()->find($log->auditable_id) : null;

                    TripEvent::create([
                        'tenant_id'      => $log->tenant_id,
                        'trip_id'        => $isTrip ? $log->auditable_id : null,
                        'order_id'       => $trip?->order_id,
                        'consignment_id' => $isTrip
                            ? $trip?->consignment_id
                            : ($log->auditable_type === \App\Models\Transport\TransportConsignment::class ? $log->auditable_id : null),
                        'container_id'   => $isContainer ? $log->auditable_id : null,
                        'event_type'     => $type,
                        'category'       => TripEventType::categoryFor($type) ?? TripEventType::OPERATIONAL,
                        'source'         => TripEventType::sourceFor($type) ?? TripEventType::SYSTEM,
                        // The audit row's own time. A backfilled timeline must
                        // read as the history it is, not as a burst of events
                        // on the day somebody ran a command.
                        'occurred_at'    => $log->occurred_at ?? $log->created_at,
                        'recorded_at'    => now(),
                        'summary'        => TripEventType::label($type),
                        'detail'         => [
                            'backfilled_from_audit_id' => $log->id,
                            'action'                   => $log->action,
                        ],
                        'actor_id'       => $log->actor_id,
                        'actor_name'     => $log->actor_name,
                        'actor_role'     => $log->actor_role,
                    ]);

                    $written++;
                }
            });

        $this->info(sprintf(
            '%s %d event(s) from the audit trail. %d already carried across.',
            $pretend ? 'Would write' : 'Wrote', $written, $skipped,
        ));

        if (! $pretend && $written > 0) {
            $this->line('  Run it again safely — every row records the audit id it came from.');
        }

        return self::SUCCESS;
    }

    private function typeFor(TransportAuditLog $log): ?string
    {
        if (isset(self::MAP[$log->action])) {
            return self::MAP[$log->action];
        }

        if ($log->action === 'transport.trip.status_changed') {
            return self::BY_STATUS[$log->new_values['status'] ?? ''] ?? null;
        }

        return null;
    }
}
