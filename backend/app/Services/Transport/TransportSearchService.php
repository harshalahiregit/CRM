<?php

namespace App\Services\Transport;

use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Contracts\DriverDirectory;
use App\Models\Transport\TransportConsignment;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TransportDocumentEntity;
use App\Models\Transport\TransportDocument;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Support\Transport\TripStatus;
use Illuminate\Support\Facades\DB;

/**
 * One box, any identifier — TM-001 §8, "Universal Search & Timeline".
 *
 * TM-001 puts this squarely in Person 1's column:
 *
 *   "Search by Container, Consignment, Order, Trip, LR, DO, Vehicle, Driver
 *    and Invoice"
 *
 * and CTD §4 names the same list, adding: "All relevant search paths must
 * ultimately lead to the same Digital Passport."
 *
 * ── EXACT IDENTIFIERS ONLY. NOTHING FUZZY. ───────────────────────────────
 * This resolves an identifier a person has in front of them — off a document,
 * a gate pass, an email. It is NOT a keyword search: no partial matching, no
 * scoring, no cross-entity guessing. A registration number finds a vehicle, a
 * trip number finds a trip, and a string matching neither finds nothing.
 *
 * That restraint is deliberate. Fuzzy search over eight entities is its own
 * feature with its own ranking rules, and a half-built one that sometimes
 * returns the wrong record is worse than one that returns nothing.
 *
 * ── WHAT IS SEARCHABLE TODAY, AND WHAT IS NOT ────────────────────────────
 * Built: container number, consignment number, order number, trip number,
 * customer reference, vehicle registration, driver name.
 *
 * NOT built, and not stubbed:
 *   LR / DO      no entity yet. D-41 ruled they stay DOCUMENTS in
 *                transport_documents; blocked on P3 adding
 *                TransportDocumentEntity::CONSIGNMENT and 'delivery_order' to
 *                ENUM-006 (REQUEST-person3-document-entity.md). CTD-004/005.
 *   Invoice      P3's; TripBill carries invoice_id but the invoice itself is
 *                Accounts'. No read contract.
 *   POD number   POD is a document TYPE, not a numbered entity (CTD-017).
 *
 * ── EVERY QUERY IS TENANT-SCOPED AT THE LOOKUP ───────────────────────────
 * CTD-022. Another workspace's identifier returns nothing at all — never a
 * "you may not see this", which would confirm the record exists.
 */
class TransportSearchService
{
    /**
     * Resolve an identifier to the record it names.
     *
     * Ordered by how a person actually searches: the container number is the
     * anchor (CTD §4, "the preferred entry point"), so it is tried first and
     * the rest follow. The first exact match wins; there is no ranking because
     * these identifier spaces do not overlap.
     *
     * @return array<string,mixed>|null  null when nothing matches
     */
    public function resolve(string $term, int $tenantId): ?array
    {
        $term = trim($term);

        if ($term === '') {
            return null;
        }

        foreach ([
            fn () => $this->container($term, $tenantId),
            fn () => $this->trip($term, $tenantId),
            fn () => $this->order($term, $tenantId),
            fn () => $this->consignment($term, $tenantId),
            // CTD §150 NON-NEGOTIABLE: "LR and DO must be searchable", and
            // CTD §4 lists both among the entry points that "must ultimately
            // lead to the same Digital Passport". Unbuildable until 2026-09-16,
            // when P3 made a consignment something a document can be filed
            // against (D-41). Ahead of the customer reference because an LR
            // number is a Sangoé identifier and a customer reference is
            // somebody else's — a collision between the two should resolve to
            // ours.
            fn () => $this->shipmentDocument($term, $tenantId),
            fn () => $this->customerReference($term, $tenantId),
            fn () => $this->vehicle($term, $tenantId),
            fn () => $this->driver($term, $tenantId),
        ] as $attempt) {
            if ($hit = $attempt()) {
                return $this->throughToPassport($hit, $tenantId);
            }
        }

        return null;
    }

    /* ── §4's closing sentence ───────────────────────────────────────────
     *
     *   "All relevant search paths must ultimately lead to the same Digital
     *    Passport."
     *
     * Every key above finds SOMETHING. Only one of them — a container number —
     * found the passport itself; the rest stopped at whatever they named and
     * left the user on a list. This carries each of them the rest of the way.
     *
     * ── THE CHAIN IS THE SAME ONE THE PASSPORT ITSELF WALKS ─────────────
     * order → consignment → container, and vehicle/driver → trip → consignment
     * → container. None of it is new routing; it is the relationship that
     * ContainerPassportService already reads, followed in the other direction.
     *
     * ── WHERE IT STOPS, IT SAYS SO ─────────────────────────────────────
     * A consignment of loose cargo has no container (§8 allows "other cargo
     * references"); a vehicle may be carrying nothing. Those are not failures
     * and they are not passports either, so the walk stops at the last real
     * record and `note` says which hop ran out. Returning nothing, or returning
     * a passport that is not this thing's passport, would both be worse.
     */
    private function throughToPassport(array $hit, int $tenantId): array
    {
        // THE ONE DELIBERATE DEPARTURE FROM §4's LETTER — approved 2026-09-19,
        // reasoning in D-117.
        //
        // A trip number keeps going to the trip page. §4's intent is that the
        // whole story stays reachable, not that every screen is the same
        // screen, and somebody typing TRP-2026-000034 is a dispatcher who wants
        // the working screen. Answering with a traceability view would be
        // answering a question they did not ask. The passport is one click away
        // on that page, which is the condition attached to the approval.
        if ($hit['type'] === 'trip') {
            return $hit;
        }

        if ($hit['type'] === 'container') {
            return $hit;    // already there
        }

        if ($hit['type'] === 'vehicle' || $hit['type'] === 'driver') {
            $journeys = $this->journeysFor($hit, $tenantId);

            // Several. Hand back the list rather than choosing one.
            if (isset($journeys['options'])) {
                return array_merge($hit, $journeys);
            }

            [$consignmentId, $via, $note] = [
                $journeys['consignment_id'], $journeys['via'], $journeys['note'],
            ];
        } else {
            [$consignmentId, $via, $note] = match ($hit['type']) {
                'consignment' => [$hit['id'], [], null],
                'order'       => $this->consignmentForOrder($hit['id'], $tenantId),
                default       => [null, [], null],
            };
        }

        if ($consignmentId === null) {
            return $note ? array_merge($hit, ['note' => $note, 'via' => $via]) : $hit;
        }

        $container = $this->containerOn($consignmentId, $tenantId);

        if (! $container) {
            return array_merge($hit, [
                'via'  => $via,
                'note' => 'This consignment has no container on it, so there is no container '
                    .'passport to open. Loose cargo is recorded on the consignment itself.',
            ]);
        }

        // array_merge, NOT the `+` union operator. `$hit` already carries a
        // `path`, and `+` keeps the LEFT operand's keys — so `$hit + ['path' =>
        // …]` silently leaves the original destination in place. Written that
        // way first: the passport attached correctly and every search still
        // landed where it used to, which looked like the feature working.
        return array_merge($hit, [
            'via'      => $via,
            'passport' => [
                'path'             => '/app/transport/containers/'.$container->id,
                'container_number' => $container->container_number,
            ],
            // The passport becomes the destination. `path` is what both the
            // command palette and the landing page navigate to, so setting it
            // here is what makes §4 true for EVERY caller at once rather than
            // for whichever screen remembered to follow the chain.
            'path'     => '/app/transport/containers/'.$container->id,
        ]);
    }

    /** An order's consignment. One order, one consignment, in this build. */
    private function consignmentForOrder(int $orderId, int $tenantId): array
    {
        $c = TransportConsignment::forTenant($tenantId)->where('order_id', $orderId)->first();

        return $c
            ? [$c->id, [$c->consignment_number], null]
            : [null, [], 'No consignment has been raised against this order yet.'];
    }

    /**
     * A vehicle or a driver is an ASSET WITH A HISTORY, not a journey.
     *
     * That distinction is the whole of this method. A container number names
     * one journey and always will; a plate names as many journeys as the truck
     * has run. So:
     *
     *   one live or recent trip  → carry on to its passport
     *   several                  → stop, and hand the caller the list
     *   none                     → stop, and say the truck is not on a trip
     *
     * Straight-through on "several" would silently pick one journey out of
     * many, which is guessing dressed as an answer.
     */
    private function journeysFor(array $hit, int $tenantId): array
    {
        $column = $hit['type'] === 'vehicle' ? 'vehicle_id' : 'driver_id';

        $trips = TransportTrip::forTenant($tenantId)
            ->where($column, $hit['id'])
            ->orderByRaw('CASE WHEN status IN (?, ?, ?, ?) THEN 0 ELSE 1 END', [
                TripStatus::DISPATCHED, TripStatus::IN_TRANSIT,
                TripStatus::DELIVERED, TripStatus::ALLOCATED,
            ])
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        if ($trips->isEmpty()) {
            return ['consignment_id' => null, 'via' => [], 'note' => $hit['type'] === 'vehicle'
                ? 'No trip has run on this vehicle yet, so there is nothing to trace.'
                : 'No trip has run with this driver yet, so there is nothing to trace.'];
        }

        if ($trips->count() > 1) {
            return ['options' => [
                'heading' => $trips->count().' trips on this '
                    .($hit['type'] === 'vehicle' ? 'vehicle' : 'driver').', most recent first',
                'items' => $trips->map(function (TransportTrip $t) use ($tenantId) {
                    $container = $t->consignment_id
                        ? $this->containerOn($t->consignment_id, $tenantId)
                        : null;

                    return [
                        'trip_number' => $t->trip_number,
                        'status'      => $t->status,
                        'label'       => TripStatus::label($t->status),
                        'trip_path'   => '/app/transport/trips/'.$t->id,
                        // The passport for each, where there is one — so the
                        // list is still a set of routes to §4's destination and
                        // not a dead end with extra steps.
                        'path'        => $container
                            ? '/app/transport/containers/'.$container->id
                            : '/app/transport/trips/'.$t->id,
                        'container_number' => $container?->container_number,
                    ];
                })->all(),
            ]];
        }

        $trip = $trips->first();

        if (! $trip->consignment_id) {
            return ['consignment_id' => null, 'via' => [$trip->trip_number],
                'note' => 'Trip '.$trip->trip_number.' has no consignment on it, so there is no passport.'];
        }

        return ['consignment_id' => $trip->consignment_id, 'via' => [$trip->trip_number], 'note' => null];
    }

    /** The container currently on a consignment, if there is one. */
    private function containerOn(int $consignmentId, int $tenantId): ?TransportContainer
    {
        $id = DB::table('transport_consignment_containers')
            ->where('tenant_id', $tenantId)
            ->where('consignment_id', $consignmentId)
            ->whereNull('detached_at')
            ->orderByDesc('id')
            ->value('container_id');

        return $id ? TransportContainer::forTenant($tenantId)->find($id) : null;
    }

    /**
     * CTD-001 and CTD §7 — the anchor.
     *
     * Matched on the NORMALISED key, so ABCD1234567, abcd-123456-7 and
     * ABCD 1234 567 all find the same box. The result carries what was entered
     * beside what it matched, which is what the containers list already shows.
     */
    private function container(string $term, int $tenantId): ?array
    {
        $container = TransportContainer::forTenant($tenantId)
            ->where('container_number_normalized', TransportContainer::normalise($term))
            ->first();

        return $container ? $this->hit('container', $container->id,
            $container->container_number, 'Container',
            '/app/transport/containers/'.$container->id,
            matched: $container->container_number_normalized) : null;
    }

    private function trip(string $term, int $tenantId): ?array
    {
        $trip = TransportTrip::forTenant($tenantId)->where('trip_number', $term)->first();

        return $trip ? $this->hit('trip', $trip->id, $trip->trip_number, 'Trip',
            '/app/transport/trips/'.$trip->id) : null;
    }

    private function order(string $term, int $tenantId): ?array
    {
        $order = TransportOrder::forTenant($tenantId)->where('order_number', $term)->first();

        return $order ? $this->hit('order', $order->id, $order->order_number, 'Transport order',
            '/app/transport/orders/'.$order->id) : null;
    }

    /**
     * A consignment has no page of its own — its detail is a drawer on the
     * list, so the deep link opens that drawer (the same route the trip page's
     * "What is being moved" card uses).
     */
    private function consignment(string $term, int $tenantId): ?array
    {
        $c = TransportConsignment::forTenant($tenantId)->where('consignment_number', $term)->first();

        return $c ? $this->hit('consignment', $c->id, $c->consignment_number, 'Consignment',
            '/app/transport/consignments?open='.$c->id) : null;
    }

    /** CTD §4 names "Customer Reference" — the customer's own PO, off their paperwork. */
    /**
     * An LR or DO number — CTD §4, and §150's non-negotiable.
     *
     * D-41 ruled that an LR and a DO stay DOCUMENTS rather than getting tables
     * of their own, so "search by LR number" is a search of
     * `transport_documents.document_number` for a document filed against a
     * consignment. It resolves to the consignment, because that is the thing
     * the number identifies and the thing a passport can be opened from.
     *
     * Only LR and DELIVERY_ORDER, deliberately. The same column holds e-way
     * bill and invoice numbers, and those are other systems' identifiers with
     * their own meanings — CTD §4 lists "Invoice Number" as a separate entry
     * point, and answering an invoice search with a consignment would be
     * guessing at what somebody meant.
     *
     * Case-insensitive and trimmed, and nothing more: unlike a container number
     * there is no normalisation rule for an LR anywhere in the package, and
     * inventing one would be D-9 — stripping dashes from "LR-2026-0001" assumes
     * a format no document defines.
     */
    private function shipmentDocument(string $term, int $tenantId): ?array
    {
        $doc = TransportDocument::forTenant($tenantId)
            ->where('entity_type', TransportDocumentEntity::CONSIGNMENT)
            ->whereIn('document_type', [TransportDocumentType::LR, TransportDocumentType::DELIVERY_ORDER])
            ->whereRaw('LOWER(document_number) = ?', [mb_strtolower($term)])
            // The newest version wins. STOS-DOC §26 makes a renewal a new
            // version rather than an overwrite, so one LR number can have
            // several rows and the current one is the one somebody means.
            ->orderByDesc('version')
            ->first();

        if (! $doc) {
            return null;
        }

        $consignment = TransportConsignment::forTenant($tenantId)->find($doc->entity_id);

        if (! $consignment) {
            // A document whose consignment has gone. Say nothing rather than
            // offer a link to a record that will 404.
            return null;
        }

        return $this->hit(
            'consignment',
            $consignment->id,
            $consignment->consignment_number,
            TransportDocumentType::label($doc->document_type),
            '/app/transport/consignments?open='.$consignment->id,
            matched: TransportDocumentType::label($doc->document_type).' '.$doc->document_number,
        );
    }

    private function customerReference(string $term, int $tenantId): ?array
    {
        $c = TransportConsignment::forTenant($tenantId)->where('customer_reference', $term)->first();

        return $c ? $this->hit('consignment', $c->id, $c->consignment_number, 'Consignment',
            '/app/transport/consignments?open='.$c->id, matched: 'customer reference '.$term) : null;
    }

    /**
     * Vehicle and driver resolve against P1's PLACEHOLDER tables, not Fleet's —
     * there is no read contract to Fleet (D-100). When allocation is repointed,
     * these two follow.
     *
     * ── WHERE THEY LAND, AND THE THING THAT WAS WRONG ABOUT IT ──────────
     * These used to return Fleet's list page and stop there, and §4 is explicit
     * that "all relevant search paths must ultimately lead to the same Digital
     * Passport". throughToPassport() now carries them on: plate → trip →
     * consignment → container.
     *
     * That walk was assumed to be blocked on the Fleet repoint (D-110/D-116)
     * and it is not. `transport_trips.vehicle_id` and `TransportVehicle.id` are
     * the SAME id space, so the chain resolves today, entirely inside our own
     * tables. The dead end was a leftover: when P2 repointed
     * /app/transport/vehicles at their own components (D-62) this path was
     * pointed at the new screen and never revisited against §4.
     *
     * ── WHAT DOES WAIT FOR THE REPOINT ─────────────────────────────────
     * A plate Fleet holds that our placeholder table does not. It will not
     * resolve here at all, and the landing page says so in a sentence rather
     * than returning nothing and looking broken. Today both tables carry the
     * same trucks, which is exactly why this limit is easy to miss.
     */
    private function vehicle(string $term, int $tenantId): ?array
    {
        $normalised = TransportVehicle::normalizeRegistration($term);

        // ── REPOINTED ONTO FLEET, 2026-09-23 — D-140 ─────────────────────
        // This read `transport_vehicles`. Under the read-only ruling every new
        // vehicle exists ONLY in Fleet, so a plate search would have found the
        // migrated trucks — because they still sit in the legacy table — and
        // silently failed for every vehicle created from today. Measured before
        // the change: MH09WALK99, created through Fleet's own form, returned
        // NOT FOUND while the two migrated plates resolved.
        //
        // ── WHY TWO CLAUSES ──────────────────────────────────────────────
        // `registration_normalized` is the column built for this, and Fleet's
        // model does not populate it on save — the migrated rows have it, a row
        // created through Fleet's form has NULL. Normalising the stored side
        // here would paper over that and the next reader of that table would hit
        // it again, so the stored side is matched as it is, and only the INPUT
        // is normalised. Where the column is populated a spaced plate matches;
        // where it is not, an exactly typed plate still does.
        //
        // Who should populate it is D-141, and it is P2's model.
        $v = Vehicle::forCompany($tenantId)
            ->where(fn ($q) => $q->where('registration_normalized', $normalised)
                ->orWhere('registration_number', $term))
            ->first();

        // The path here is the FALLBACK — where the user goes when the chain
        // runs out (a truck with no trips). throughToPassport() overwrites it
        // with the passport whenever one is reachable.
        return $v ? $this->hit('vehicle', $v->id, $v->registration_number, 'Vehicle',
            '/app/transport/fleet/vehicles/'.$v->id, matched: $v->registration_normalized ?? $v->registration_number) : null;
    }

    /**
     * A driver by name — through the directory, because Fleet holds no names.
     *
     * ── REPOINTED, 2026-09-23 — D-140 ────────────────────────────────────
     * This read `TransportDriver::where('name', $term)`. `driver_profiles` has
     * no `name` column at all: a driver is a reference into a directory
     * (`source` + `source_id`) plus a licence. So this could not simply be
     * repointed the way the vehicle half was — the name is not in the table the
     * id now names.
     *
     * It goes through `DriverDirectory` instead, which is where names live, and
     * which since D-134 is the composite asking BOTH registers. Without that
     * composite this would have found only CRM-sourced people and missed every
     * migrated driver — the same blind spot, arriving by a different route.
     *
     * The directory is asked for the name; the profile is what the id must
     * resolve to, because that is what a trip points at. A person in the
     * directory with no profile is not offered: there is nothing to land on.
     */
    private function driver(string $term, int $tenantId): ?array
    {
        $people = app(DriverDirectory::class)->people($tenantId, ['q' => $term]);

        foreach ($people as $person) {
            if (strcasecmp(trim($person['name'] ?? ''), trim($term)) !== 0) {
                continue;
            }

            $profile = DriverProfile::forCompany($tenantId)
                ->where('source', $person['source'])
                ->where('source_id', $person['source_id'])
                ->first();

            if ($profile) {
                return $this->hit('driver', $profile->id, $person['name'], 'Driver',
                    '/app/transport/drivers', matched: $person['ref']);
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function hit(string $type, int $id, string $label, string $kindLabel, string $path, ?string $matched = null): array
    {
        return [
            'type'  => $type,
            'id'    => $id,
            'label' => $label,
            'kind'  => $kindLabel,
            'path'  => $path,
            // What it matched ON, when that differs from what was typed. The
            // containers list already shows this and it is what makes a
            // normalised match explicable rather than magic.
            'matched_on' => $matched,
        ];
    }
}
