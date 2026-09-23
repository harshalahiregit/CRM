<?php

namespace App\Services\Transport;

use App\Models\Transport\ConsignmentContainer;
use App\Models\Transport\TransportConsignment;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TransportDocumentEntity;
use App\Models\Transport\TransportDocument;
use App\Models\Transport\TripEvent;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripBill;
use App\Models\Transport\TripCollection;
use App\Models\Transport\TripDocument;
use App\Support\Transport\TripStatus;

/**
 * Container 360 — the Digital Passport (STOS-CTD, MS-001 §14 step 2).
 *
 * TM-001 §8 puts "Consignment / Container 360", "Container search",
 * "Consignment Passport" and "Cross-module traceability" in Person 1's column,
 * and §9 restates the rule this service is built on:
 *
 *   "Container Number is the universal search/traceability anchor. Vehicle,
 *    driver, trip, finance and maintenance retain correct domain ownership."
 *
 * So the passport ANCHORS on the container and READS ACROSS — it does not own
 * what it shows. Everything here is assembled from rows that already exist;
 * this service writes nothing and has no table of its own.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ─────────────────────────────────────
 * It does not re-render other people's panels. Documents, POD, billing,
 * invoice and collection are P3's, keyed on `trip_id`; the passport reports
 * that they EXIST and links to the trip screen that owns them. Copying those
 * panels here would duplicate a boundary we spent two blocks establishing.
 *
 * It does not invent sections for data nobody has. CTD lists thirty passport
 * sections (§9) — GPS, temperature, Genset, fuel, FASTag, port/gate, feedback,
 * CAPA, profitability. None has an entity in this codebase. **An empty panel
 * implies the feature exists**, which is the rule the consignment drawer
 * already follows, so absent sections are absent rather than empty.
 *
 * ── §76: SEPARATED BY LIFECYCLE INSTANCE ─────────────────────────────────
 * "A container may later be associated with another consignment. Historical
 * Passport events must remain immutable and separated by lifecycle instance."
 *
 * One passport per CONTAINER, with the CURRENT attachment prominent and prior
 * ones listed beside it — ruled 2026-09-17. A page per attachment would
 * fragment the single thing the screen exists to show: where this box is and
 * how it got there.
 */
class ContainerPassportService
{
    public function __construct(
        private ContainerService $containers,
        private PretripService $pretrip,
    ) {
    }

    /**
     * The whole passport for one container.
     *
     * @return array<string,mixed>
     */
    public function forContainer(int $containerId, int $tenantId): array
    {
        // Tenant-scoped AT the lookup: another workspace's container reads as
        // "no such container", never "not yours" (CTD-022).
        $container = $this->containers->find($containerId, $tenantId);

        $history = $this->containers->history($container->id, $tenantId);
        $current = $history->firstWhere('detached_at', null);
        $trip    = $this->tripFor($current, $tenantId);

        return [
            'container'   => $container,
            'lifecycle'   => $this->lifecycle($history, $current),
            'chain'       => $this->chain($current, $trip, $tenantId),
            'status'      => $this->status($container, $current, $trip),
            'readiness'   => $this->readiness($trip, $tenantId),
            'linked'      => $this->linkedRecords($trip, $tenantId),
            'timeline'    => $this->timeline($container, $current, $trip, $tenantId),
        ];
    }

    /** The trip carrying this container right now, if any. */
    private function tripFor(?ConsignmentContainer $current, int $tenantId): ?TransportTrip
    {
        if (! $current?->consignment_id) {
            return null;
        }

        return TransportTrip::forTenant($tenantId)
            ->where('consignment_id', $current->consignment_id)
            ->with([
                'customer:id,company',
                'order:id,order_number,order_status,service_type,required_at,priority',
                'consignment:id,consignment_number,customer_reference,cargo_description,package_count,gross_weight_kg',
                'vehicle:id,registration_number,vehicle_type',
                // See D-134 — Fleet holds no name; the passport shows the
                // licence until the directory lookup is wired in.
                'driver:id,source,source_id,licence_number,licence_class',
            ])
            ->latest('id')
            ->first();
    }

    /**
     * CTD §76 — this lifecycle instance, and the ones before it.
     *
     * @param  \Illuminate\Support\Collection<int,ConsignmentContainer>  $history
     * @return array<string,mixed>
     */
    private function lifecycle($history, ?ConsignmentContainer $current): array
    {
        return [
            'current'  => $current,
            'previous' => $history->filter(fn (ConsignmentContainer $a) => $a->detached_at !== null)->values(),
            'times_used' => $history->count(),
        ];
    }

    /**
     * CTD §71's two chains, in one shape.
     *
     *   Customer → Transport Order → Consignment → Container
     *   Container → Trip → Vehicle → Driver
     *
     * Every node carries the id the screen needs to link to it. A node with no
     * data is null rather than an empty object, so the UI can omit it instead
     * of rendering a blank row.
     *
     * CTD-002, CTD-003, CTD-006, CTD-007.
     *
     * @return array<string,mixed>
     */
    private function chain(?ConsignmentContainer $current, ?TransportTrip $trip, int $tenantId): array
    {
        // The trip's copy carries every column the chain needs; the attachment's
        // is column-limited for the history list. Prefer the fuller one.
        $consignment = $trip?->consignment ?? $current?->consignment;

        return [
            'customer'    => $trip?->customer ? [
                'id' => $trip->customer->id, 'name' => $trip->customer->company,
            ] : null,
            'order'       => $trip?->order ? [
                'id' => $trip->order->id, 'number' => $trip->order->order_number,
                'status' => $trip->order->order_status, 'service_type' => $trip->order->service_type,
                'required_at' => $trip->order->required_at?->toIso8601String(),
            ] : null,
            'consignment' => $consignment ? [
                'id' => $consignment->id, 'number' => $consignment->consignment_number,
                'customer_reference' => $consignment->customer_reference,
                'cargo_description'  => $consignment->cargo_description,
                'package_count'      => $consignment->package_count,
                'gross_weight_kg'    => $consignment->gross_weight_kg,
            ] : null,

            /* ── CTD-004 and CTD-005, both P0 ────────────────────────────
             *
             * "Link container to LR" · acceptance "LR visible".
             * "Link container to DO" · acceptance "DO visible".
             *
             * CTD §5's worked search example puts the LR in exactly this
             * position — Container, Status, Customer, Transport Order, **LR**,
             * Vehicle, Driver — so the chain carries it between the order and
             * the trip rather than hiding it in a documents list.
             *
             * Unbuildable until 2026-09-16. D-41 ruled that an LR and a DO stay
             * DOCUMENTS rather than getting tables of their own, and P3 made a
             * consignment something a document can be filed against. Our own
             * walk of MS-001 §14 recorded step 3 as partial for want of this,
             * and the blocker had already cleared — nobody announced it.
             */
            'lr' => $this->shipmentDocument($consignment, TransportDocumentType::LR, $tenantId),
            'do' => $this->shipmentDocument($consignment, TransportDocumentType::DELIVERY_ORDER, $tenantId),
            'trip'        => $trip ? [
                'id' => $trip->id, 'number' => $trip->trip_number, 'status' => $trip->status,
                'status_label' => TripStatus::LABELS[$trip->status] ?? $trip->status,
                'route' => $trip->route,
                'planned_departure_at' => $trip->planned_departure_at?->toIso8601String(),
                'planned_arrival_at'   => $trip->planned_arrival_at?->toIso8601String(),
                'dispatched_at'        => $trip->dispatched_at?->toIso8601String(),
            ] : null,
            // CTD-006 / CTD-007. Read from transport_vehicles / transport_drivers
            // — P1's own tables (TEAM-CONTRACTS §1a's placeholder), NOT Fleet's.
            // There is no read contract to Fleet: FleetResourceGateway carries
            // only markDispatched(). When allocation is repointed (D-100), this
            // is one of the places that follows.
            'vehicle'     => $trip?->vehicle ? [
                'id' => $trip->vehicle->id, 'registration' => $trip->vehicle->registration_number,
                'type' => $trip->vehicle->vehicle_type,
            ] : null,
            'driver'      => $trip?->driver ? [
                'id' => $trip->driver->id, 'name' => $trip->driver->name,
                'licence_class' => $trip->driver->licence_class,
            ] : null,
        ];
    }

    /**
     * CTD §11 and §12 — the status, and what it MEANS.
     *
     * §12 is explicit that the UI "must not merely show BILLING_BLOCKED" but
     * explain it. A container has no status of its own (CTD §8 — the
     * consignment carries the commercial facts), so the status shown is the
     * TRIP's, translated, with what happens next.
     *
     * @return array<string,mixed>
     */
    /**
     * The current LR or DO on this shipment, or null.
     *
     * NULL rather than an empty shape, because ChainRow renders nothing for a
     * missing value — a consignment with no LR yet shows no LR row rather than
     * a row saying "—". CTD-004's acceptance is "LR visible", and a dash is not
     * an LR.
     *
     * Newest version wins: STOS-DOC §26 makes a renewal a new version rather
     * than an overwrite, so the chain shows the one in force.
     */
    private function shipmentDocument(?TransportConsignment $consignment, string $type, int $tenantId): ?array
    {
        if (! $consignment) {
            return null;
        }

        $doc = TransportDocument::forTenant($tenantId)
            ->where('entity_type', TransportDocumentEntity::CONSIGNMENT)
            ->where('entity_id', $consignment->id)
            ->where('document_type', $type)
            ->orderByDesc('version')
            ->first();

        return $doc ? [
            'id'     => $doc->id,
            'number' => $doc->document_number,
            'label'  => TransportDocumentType::label($type),
            // A document may be filed without its number — the file itself is
            // the record. Say which, rather than rendering a blank.
            'issued_on' => $doc->issued_on?->toDateString(),
            'version'   => (int) $doc->version,
            'has_file'  => $doc->file_path !== null,
        ] : null;
    }

    private function status(TransportContainer $container, ?ConsignmentContainer $current, ?TransportTrip $trip): array
    {
        if (! $current) {
            return [
                'code' => 'not_on_a_consignment',
                'label' => 'Not on a consignment',
                'explanation' => 'This container is free. It is not carrying anything at the moment.',
                'next_action' => 'Attach it to a consignment when it is loaded.',
            ];
        }

        if (! $trip) {
            return [
                'code' => 'awaiting_trip',
                'label' => 'Loaded, no trip yet',
                'explanation' => 'This container is on consignment '
                    .$current->consignment?->consignment_number.', which no trip is carrying yet.',
                'next_action' => 'Raise a trip for that consignment.',
            ];
        }

        return [
            'code'        => $trip->status,
            'label'       => TripStatus::LABELS[$trip->status] ?? $trip->status,
            'explanation' => $this->explain($trip),
            'next_action' => $this->nextAction($trip),
        ];
    }

    /** CTD §12 — the sentence a client understands, not the enum. */
    private function explain(TransportTrip $trip): string
    {
        $on = 'On trip '.$trip->trip_number;

        return match ($trip->status) {
            TripStatus::DRAFT             => $on.', which is still being set up.',
            TripStatus::VIABILITY_PENDING => $on.', which is waiting for someone to approve it.',
            TripStatus::APPROVED          => $on.', approved but with no vehicle or driver yet.',
            TripStatus::ALLOCATED         => $on.', with a vehicle and driver assigned.',
            TripStatus::PRETRIP_OK        => $on.', checked and cleared to leave.',
            // `dispatched` is RELEASED, not moving. Before Block 3 the two were
            // indistinguishable on this screen, which meant a container sitting
            // in a yard read as one on the road.
            TripStatus::DISPATCHED        => $on.', released but not recorded as having left yet.',
            TripStatus::IN_TRANSIT        => $on.', on the road since '
                                             .($trip->departed_at?->format('j M, H:i') ?? 'departure').'.',
            TripStatus::DELIVERED         => $on.', delivered on '
                                             .($trip->delivered_at?->format('j M Y') ?? 'arrival')
                                             .' and waiting for proof of delivery.',
            TripStatus::POD_VERIFIED      => $on.', delivered with proof of delivery on file.',
            TripStatus::BILLABLE          => $on.', delivered and ready to invoice.',
            TripStatus::BILLED            => $on.', delivered and invoiced.',
            TripStatus::COLLECTION_PENDING => $on.', delivered and invoiced, with payment outstanding.',
            TripStatus::CLOSED            => $on.', which is finished and closed.',
            default                       => $on.'.',
        };
    }

    private function nextAction(TransportTrip $trip): string
    {
        return match ($trip->status) {
            TripStatus::DRAFT             => 'Complete the trip and submit it for viability.',
            TripStatus::VIABILITY_PENDING => 'Approve the trip, or send it back for correction.',
            TripStatus::APPROVED          => 'Assign a vehicle and a driver.',
            TripStatus::ALLOCATED         => 'Run the pre-trip checks.',
            TripStatus::PRETRIP_OK        => 'Confirm dispatch.',
            TripStatus::DISPATCHED        => 'Record the departure once the vehicle leaves.',
            TripStatus::IN_TRANSIT        => 'Record the delivery when it arrives.',
            TripStatus::DELIVERED         => 'Upload and verify the proof of delivery.',
            TripStatus::POD_VERIFIED      => 'Hand the trip to Accounts for billing.',
            TripStatus::BILLABLE          => 'Accounts raise the invoice.',
            TripStatus::BILLED            => 'Open a collection and chase payment.',
            TripStatus::COLLECTION_PENDING => 'Collect the balance, then close the trip.',
            // Terminal. Saying "no action" here is not a fallback — it is the
            // answer, and it is different from the default below, which means
            // "this state has nothing defined".
            TripStatus::CLOSED            => 'Nothing. This trip is closed.',
            default                       => 'No action is waiting on this container.',
        };
    }

    /**
     * MS-001 §14 step 5 — "dispatch eligibility".
     *
     * Added after reading §14: the demonstration script asks for it explicitly
     * and it is ours (PretripService). The COMPLIANCE half of that step is
     * P2's and P3's and has no read contract, so only eligibility appears.
     *
     * @return array<string,mixed>|null
     */
    private function readiness(?TransportTrip $trip, int $tenantId): ?array
    {
        if (! $trip) {
            return null;
        }

        return $this->pretrip->readiness($trip, $tenantId);
    }

    /**
     * CTD-016, CTD-017, CTD-019, CTD-020 — P3's records, REPORTED not rendered.
     *
     * Each is a count and a flag. The screen says "3 documents, POD verified"
     * and links to the trip, which already renders P3's panels in full. The
     * passport does not reimplement them.
     *
     * A section with a zero count is returned as `null` so the UI omits it
     * rather than showing an empty panel.
     *
     * @return array<string,mixed>|null
     */
    private function linkedRecords(?TransportTrip $trip, int $tenantId): ?array
    {
        if (! $trip) {
            return null;
        }

        $documents = TripDocument::forTenant($tenantId)->where('trip_id', $trip->id)->get();
        $bill      = TripBill::forTenant($tenantId)->where('trip_id', $trip->id)->latest('id')->first();
        $collected = TripCollection::forTenant($tenantId)->where('trip_id', $trip->id)->count();

        $out = [];

        if ($documents->isNotEmpty()) {
            $out['documents'] = [
                'total'    => $documents->count(),
                'verified' => $documents->whereNotNull('verified_at')->count(),
                // CTD-017. POD is a document TYPE, not a separate entity.
                'pod'      => $documents->first(fn ($d) => str_contains(strtolower((string) $d->document_type), 'pod')) !== null,
            ];
        }

        if ($bill) {
            $out['billing'] = [
                'status'  => $bill->status,
                'amount'  => $bill->billable_amount,
                'invoiced' => $bill->invoice_id !== null,
            ];
        }

        if ($collected > 0) {
            $out['collections'] = ['total' => $collected];
        }

        return $out === [] ? null : $out;
    }

    /**
     * CTD-021 and CTD §31–§33 — one chronological timeline across the chain.
     *
     * The container, its consignment and its trip each carry their own audit
     * rows. A person tracing a box does not care which table an event was
     * written to, so they are merged and sorted by time.
     *
     * §33 requires each event to name its SOURCE. Every row here is a user or
     * system action recorded by this module, so the source is derived from the
     * subject that produced it — container, consignment, trip. GPS, SENSOR,
     * ACCOUNTING and the rest are listed in §33 but have no producer in this
     * codebase; they will appear here when something writes them, and inventing
     * a source column for feeds that do not exist would be the D-9 mistake.
     *
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function timeline(
        TransportContainer $container,
        ?ConsignmentContainer $current,
        ?TransportTrip $trip,
        int $tenantId,
    ) {
        /* ── READS trip_events NOW, NOT THE AUDIT LOG ────────────────────
         *
         * It read `transport_audit_logs` until 2026-09-18, and read it well —
         * but an audit row is a FIELD CHANGE, and this is meant to be a
         * chronology of THINGS THAT HAPPENED, from every connected system.
         * CTD §32: "Timeline must combine events from all connected systems."
         * Half of CTD §31's worked example belongs to P2 and P3, and neither
         * could ever have written into our audit trail.
         *
         * The audit log has not moved and has not changed — it is still the
         * compliance record of what this module did. Existing trips were
         * carried across by `stos:backfill-trip-events`, so nothing that had a
         * timeline lost one.
         */
        $events = TripEvent::forTenant($tenantId)
            ->where(function ($q) use ($container, $current, $trip) {
                $q->where('container_id', $container->id);

                if ($current?->consignment_id) {
                    $q->orWhere('consignment_id', $current->consignment_id);
                }

                if ($trip) {
                    $q->orWhere('trip_id', $trip->id);
                }
            })
            ->chronological()
            ->limit(200)
            ->get();

        return $events->map(fn (TripEvent $e) => [
            'at'       => $e->occurred_at?->toIso8601String(),
            'action'   => $e->event_type,
            'label'    => $e->label(),
            // CTD §35's filters, which is what the screen groups by now.
            'category' => $e->category,
            // CTD §32: "each event should identify its source."
            'source'   => $e->source,
            'actor'    => $e->actor_name,
            'role'     => $e->actor_role,
            'details'  => $e->detail,
            // CTD §34 — a correction is visible AS a correction.
            'corrects' => $e->corrects_event_id,
            // An unregistered type still renders; this is how a reader knows it
            // has not been declared yet rather than wondering what it is.
            'registered' => $e->isRegistered(),
        ]);
    }

    /**
     * `transport.trip.status_changed` → "Trip status changed".
     *
     * CTD §12's principle applied to the timeline: the reader should not have
     * to decode a dotted action name.
     */
    private function humanise(string $action): string
    {
        $tail = str_replace('transport.', '', $action);

        return ucfirst(str_replace(['.', '_'], ' ', $tail));
    }
}
