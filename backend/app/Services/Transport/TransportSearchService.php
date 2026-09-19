<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportConsignment;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TransportDocumentEntity;
use App\Models\Transport\TransportDocument;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;

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
                return $hit;
            }
        }

        return null;
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
     * The route is Fleet's screen, because P1's vehicle pages are no longer
     * reachable (D-62): P2 repointed /app/transport/vehicles at their own
     * components. Searching still finds the row we hold; the link goes where a
     * user can actually look at it.
     */
    private function vehicle(string $term, int $tenantId): ?array
    {
        $v = TransportVehicle::forTenant($tenantId)
            ->where('registration_normalized', TransportVehicle::normalizeRegistration($term))
            ->first();

        return $v ? $this->hit('vehicle', $v->id, $v->registration_number, 'Vehicle',
            '/app/transport/vehicles', matched: $v->registration_normalized) : null;
    }

    private function driver(string $term, int $tenantId): ?array
    {
        $d = TransportDriver::forTenant($tenantId)->where('name', $term)->first();

        return $d ? $this->hit('driver', $d->id, $d->name, 'Driver',
            '/app/transport/drivers') : null;
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
