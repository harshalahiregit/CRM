<?php

namespace App\Support\Transport;

/**
 * What a customer may be shown of a Transport record — and what must never
 * leave the building.
 *
 * ── ONE LIST, READ BY BOTH SIDES ─────────────────────────────────────────
 * The allowed columns here are what the portal endpoints SELECT, and the deny
 * list is what the leak test asserts never appears in a portal response. They
 * are in one file on purpose: written in two places they drift, and the day
 * they drift is the day the guard agrees with the bug.
 *
 * ── THE SELECT IS THE WHITELIST, NOT A FILTER AFTER IT ───────────────────
 * The portal's own convention, which this follows rather than reinvents:
 * `DB::table(...)->get([named columns])`. Nothing is loaded and then stripped.
 * A column added to `transport_trips` tomorrow cannot leak through a query that
 * never names it, however careless the next person is — which a presenter or a
 * `makeHidden()` cannot promise, because both start from the full row.
 *
 * ── WHERE THE DENY LIST CAME FROM ────────────────────────────────────────
 * An audit of every Transport table against STOS-CLP §3 and §23:
 *
 *   §3  "Internal salary, profitability, penalties, management notes and
 *        internal CIA analysis are excluded."
 *   §23 "No internal salary/profitability/private HR/other-customer data."
 *
 * Two tables are absent from the allow side entirely rather than partly listed:
 * `trip_costs` and `trip_advances` are internal in their whole, and naming a
 * safe column on either would invite somebody to select the rest.
 *
 * `trip_documents.file_path` deserves its own sentence. STOS-SEC-002 forbids
 * exposing unrestricted storage paths for protected documents, so a client
 * document endpoint — when one is ruled on — serves a streamed, authorised
 * download and never a path.
 */
final class ClientVisibleFields
{
    /**
     * The Shipments list.
     *
     * Aliased because the portal's convention is to join and alias rather than
     * eager-load a relation — a flat row has no nesting for a field to hide in.
     *
     * `status` is the raw enum and is deliberately NOT sent as-is; the endpoint
     * maps it to plain words (CLP §27: "status must be understandable without
     * technical language"). It is selected because the map needs it.
     */
    public const SHIPMENT_COLUMNS = [
        't.id',
        't.trip_number',
        't.status',
        't.route',
        't.planned_departure_at',
        't.planned_arrival_at',
        't.departed_at',
        't.delivered_at',
        'c.consignment_number',
        'c.customer_reference',
        'c.cargo_description',
    ];

    /**
     * Never in a portal response, by table.
     *
     * Read by the leak test. Adding a column to a Transport table does not
     * require adding it here — the select is the whitelist — but anything a
     * reader might be tempted to expose belongs on this list so the guard
     * refuses it explicitly.
     */
    public const DENIED = [
        'transport_trips' => [
            'approved_freight', 'closure_reason', 'rejection_reason',
            'dispatched_by', 'departed_by', 'delivered_by', 'closed_by',
            'approved_by', 'created_by', 'updated_by',
        ],
        'transport_orders' => ['rate_reference', 'created_by', 'updated_by'],
        'trip_assignments' => ['reason', 'override_reason', 'approved_by', 'created_by', 'updated_by'],
        'transport_drivers' => [
            'driver_code', 'licence_number', 'licence_normalized', 'licence_class',
            'licence_valid_from', 'licence_valid_until', 'availability',
        ],
        'trip_bills'       => ['notes', 'prepared_by', 'invoiced_by'],
        'trip_collections' => ['blocker_reason', 'notes', 'last_followed_up_by'],
        'trip_exceptions'  => ['resolution_note', 'raised_by', 'acknowledged_by', 'resolved_by'],
        'trip_documents'   => ['file_path', 'file_hash', 'rejection_reason', 'notes', 'uploaded_by', 'verified_by'],
        // Internal in their entirety — no column on either is client-visible.
        'trip_costs'       => ['*'],
        'trip_advances'    => ['*'],
    ];

    /** Every denied column name, flattened, for a response-key assertion. */
    public static function deniedKeys(): array
    {
        $out = [];

        foreach (self::DENIED as $columns) {
            foreach ($columns as $column) {
                if ($column !== '*') {
                    $out[] = $column;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The trip's own status, in words a customer uses.
     *
     * CLP §27 — "status must be understandable without technical language."
     * `billable` and `collection_pending` are the state machine's words, not a
     * customer's, and they were the two that leaked furthest on our internal
     * screens before the September redesign. These are the same plain phrases
     * that redesign settled on, so the portal and the office say one thing.
     */
    public const STATUS_WORDS = [
        TripStatus::DRAFT              => 'Being prepared',
        TripStatus::VIABILITY_PENDING  => 'Being prepared',
        TripStatus::APPROVED           => 'Accepted',
        TripStatus::ALLOCATED          => 'Vehicle assigned',
        TripStatus::PRETRIP_OK         => 'Ready to leave',
        TripStatus::DISPATCHED         => 'Ready to leave',
        TripStatus::IN_TRANSIT         => 'On the way',
        TripStatus::DELIVERED          => 'Delivered',
        TripStatus::POD_VERIFIED       => 'Delivered',
        TripStatus::BILLABLE           => 'Delivered',
        TripStatus::BILLED             => 'Invoiced',
        TripStatus::COLLECTION_PENDING => 'Invoiced',
        TripStatus::CLOSED             => 'Completed',
    ];

    /**
     * A customer does not need our eleven states, and showing them would be
     * the technical language §27 rules out. Anything unmapped answers with the
     * safest true thing rather than the raw token.
     */
    public static function statusWord(?string $status): string
    {
        return self::STATUS_WORDS[$status] ?? 'In progress';
    }
}
