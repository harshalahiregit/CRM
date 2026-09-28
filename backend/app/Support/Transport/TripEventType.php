<?php

namespace App\Support\Transport;

/**
 * The declared vocabulary of trip events — CTD §31, §32, §33, §101; STOS-DB §37.
 *
 * ── AN OPEN COLUMN, WITH A REGISTRY. RULED 2026-09-18 ───────────────────
 * `trip_events.event_type` accepts any string. It is NOT a locked enum, and the
 * reason is structural rather than stylistic: **half these events belong to
 * Person 2 and Person 3.** Of CTD §31's sixteen example entries, seven are
 * theirs — GPS Active, Genset ON, Port Entry, Port Exit, Documents Handed Over,
 * POD Uploaded, Billing Ready, Feedback. A locked enum would mean neither could
 * record an event without a migration from P1, and a cross-section bottleneck
 * is exactly what the three-way split exists to avoid.
 *
 * Both source documents label their lists **"Example"**. Neither closes it.
 *
 * ── SO THIS REGISTRY IS THE GUARD, AND IT IS OPEN TO EVERYONE ───────────
 * **Anyone may append to this file.** Announce it in the group; no approval,
 * no migration, no ticket. What you must not do is write a type that is not
 * here — `TripEventRegistryTest` reads every distinct `event_type` in the table
 * and fails if one is missing, so drift shows up the day it happens instead of
 * the day somebody reads a timeline and finds TemperatureAlert, temp_alert and
 * TEMPERATURE_BREACH describing one thing.
 *
 * ── HOW TO ADD ONE ──────────────────────────────────────────────────────
 *   'your.event' => ['<category>', '<source>', '<owner>', 'Human sentence', '<cite>'],
 *
 * `category` is one of CTD §101's nine. `source` is one of CTD §33's ten.
 * `owner` is P1, P2 or P3 — who emits it, not who reads it. `cite` is the line
 * it comes from, or 'derived' when it is ours: the same discipline every column
 * in the migration follows, because Step 11 registers no events table at all
 * (D-113) and a citation is all that stands in for the missing registry row.
 */
final class TripEventType
{
    /* ── CTD §101's nine branches of the stream ──────────────────────── */

    public const COMMERCIAL  = 'commercial';
    public const OPERATIONAL = 'operational';
    public const DOCUMENT    = 'document';
    public const COMPLIANCE  = 'compliance';
    public const GPS         = 'gps';
    public const TEMPERATURE = 'temperature';
    public const FINANCIAL   = 'financial';
    public const CUSTOMER    = 'customer';
    public const QUALITY     = 'quality';

    /**
     * CTD §101's list, which is the stream's own definition.
     *
     * §35 gives a SEVEN-item filter list for one screen — documents,
     * operations, GPS, temperature, financial, customer, incident. It is §101's
     * nine minus commercial, compliance and quality, plus "incident". Read
     * together, §35's "incident" is §101's "quality"; the two lists are one
     * list seen from two places, and §101 is the one that defines the stream
     * rather than a filter bar. Recorded rather than silently reconciled.
     */
    public const CATEGORIES = [
        self::COMMERCIAL, self::OPERATIONAL, self::DOCUMENT, self::COMPLIANCE,
        self::GPS, self::TEMPERATURE, self::FINANCIAL, self::CUSTOMER, self::QUALITY,
    ];

    /** CTD §35's filter list, and how each maps onto the nine above. */
    public const CTD_35_FILTERS = [
        'documents'   => self::DOCUMENT,
        'operations'  => self::OPERATIONAL,
        'gps'         => self::GPS,
        'temperature' => self::TEMPERATURE,
        'financial'   => self::FINANCIAL,
        'customer'    => self::CUSTOMER,
        'incident'    => self::QUALITY,
    ];

    /* ── CTD §33's event sources ─────────────────────────────────────── */

    public const USER         = 'user';
    public const SYSTEM       = 'system';
    public const GPS_DEVICE   = 'gps';
    public const SENSOR       = 'sensor';
    public const ACCOUNTING   = 'accounting';
    public const HR           = 'hr';
    public const COMPLIANCE_SRC = 'compliance';
    public const CUSTOMER_SRC = 'customer';
    public const DRIVER       = 'driver';
    public const EXTERNAL_API = 'external_api';

    /** CTD §33's ten, verbatim. Declared in full; two are reachable today. */
    public const SOURCES = [
        self::USER, self::SYSTEM, self::GPS_DEVICE, self::SENSOR, self::ACCOUNTING,
        self::HR, self::COMPLIANCE_SRC, self::CUSTOMER_SRC, self::DRIVER, self::EXTERNAL_API,
    ];

    /* ── Owners ──────────────────────────────────────────────────────── */

    public const P1 = 'P1';   // core, orders, consignments, containers, trips
    public const P2 = 'P2';   // fleet, telemetry, allocation
    public const P3 = 'P3';   // documents, POD, billing, collections, quality

    /* ── THE REGISTRY ────────────────────────────────────────────────── */

    /** @var array<string, array{0:string,1:string,2:string,3:string,4:string}> */
    public const REGISTRY = [
        /* ── P1. Emitted today. ─────────────────────────────────────── */
        'order.approved'      => [self::COMMERCIAL, self::USER, self::P1, 'Order approved', 'CTD §31 "08:10 Order Approved"'],
        'trip.created'        => [self::OPERATIONAL, self::USER, self::P1, 'Trip created', 'STOS-DB §37 "PLANNED"'],
        'trip.submitted'      => [self::COMMERCIAL, self::USER, self::P1, 'Submitted for viability', 'derived — STT-001'],
        'trip.approved'       => [self::COMMERCIAL, self::USER, self::P1, 'Trip approved', 'derived — STT-002'],
        'trip.returned'       => [self::COMMERCIAL, self::USER, self::P1, 'Sent back for correction', 'derived — STT-003'],
        'vehicle.allocated'   => [self::OPERATIONAL, self::USER, self::P1, 'Vehicle allocated', 'CTD §31 "09:20 Vehicle Allocated"'],
        'driver.allocated'    => [self::OPERATIONAL, self::USER, self::P1, 'Driver allocated', 'CTD §31 "09:15 Driver Allocated"'],
        'crew.released'       => [self::OPERATIONAL, self::USER, self::P1, 'Vehicle and driver released', 'derived'],
        'pretrip.passed'      => [self::COMPLIANCE, self::USER, self::P1, 'Pre-trip checks passed', 'STOS-DB §37 "READY"'],
        'trip.dispatched'     => [self::OPERATIONAL, self::USER, self::P1, 'Dispatched', 'CTD §31 "10:30 Dispatch"'],
        'trip.departed'       => [self::OPERATIONAL, self::USER, self::P1, 'Left the pickup point', 'STOS-DB §37 "STARTED"'],
        'trip.delivered'      => [self::OPERATIONAL, self::USER, self::P1, 'Delivery confirmed', 'CTD §31 "16:42 Delivery Confirmed"'],
        'trip.closed'         => [self::FINANCIAL, self::USER, self::P1, 'Trip closed', 'STOS-DB §37 — trip lifecycle'],
        'container.created'   => [self::OPERATIONAL, self::USER, self::P1, 'Container created', 'derived — CTD §8'],
        'consignment.created' => [self::COMMERCIAL, self::USER, self::P1, 'Consignment created', 'derived — CTD §8'],
        'container.attached'  => [self::OPERATIONAL, self::USER, self::P1, 'Container attached', 'CTD §31 "Container Assigned"'],
        'container.detached'  => [self::OPERATIONAL, self::USER, self::P1, 'Container detached', 'derived'],
        'exception.raised'    => [self::QUALITY, self::USER, self::P1, 'Exception raised', 'CTD §35 filter "incident"'],
        'exception.acknowledged' => [self::QUALITY, self::USER, self::P1, 'Exception acknowledged', 'derived — STT-015'],
        'exception.resolved'  => [self::QUALITY, self::USER, self::P1, 'Exception resolved', 'derived — STT-016'],

        /* ── P2. DECLARED, NOT EMITTED. Theirs to record. ────────────── */
        'gps.activated'       => [self::GPS, self::GPS_DEVICE, self::P2, 'GPS active', 'CTD §31 "10:32 GPS Active"'],
        'gps.position'        => [self::GPS, self::GPS_DEVICE, self::P2, 'Position reported', 'CTD §32 "GPS"'],
        'genset.on'           => [self::TEMPERATURE, self::SENSOR, self::P2, 'Generator on', 'CTD §31 "10:35 Genset ON"'],
        'genset.off'          => [self::TEMPERATURE, self::SENSOR, self::P2, 'Generator off', 'CTD §32 "Genset"'],
        'temperature.reading' => [self::TEMPERATURE, self::SENSOR, self::P2, 'Temperature reading', 'CTD §32 "temperature"'],
        'temperature.excursion' => [self::TEMPERATURE, self::SENSOR, self::P2, 'Temperature excursion', 'CTD §17'],
        'port.entry'          => [self::OPERATIONAL, self::GPS_DEVICE, self::P2, 'Port entry', 'CTD §31 "13:10 Port Entry" · STOS-DB §37 "PORT_ENTRY"'],
        'port.exit'           => [self::OPERATIONAL, self::GPS_DEVICE, self::P2, 'Port exit', 'CTD §31 "14:00 Port Exit" · STOS-DB §37 "PORT_EXIT"'],
        'gate.in'             => [self::OPERATIONAL, self::GPS_DEVICE, self::P2, 'Gate entry', 'STOS-DB §37 "GATE_IN"'],

        /* ── P3. DECLARED, NOT EMITTED. Theirs to record. ────────────── */
        'documents.handed_over' => [self::DOCUMENT, self::USER, self::P3, 'Documents handed over', 'CTD §31 "10:05 Documents Handed Over"'],
        'pod.uploaded'        => [self::DOCUMENT, self::DRIVER, self::P3, 'POD uploaded', 'CTD §31 "17:00 POD Uploaded"'],
        'pod.verified'        => [self::DOCUMENT, self::USER, self::P3, 'POD verified', 'derived — STT-008'],
        'billing.ready'       => [self::FINANCIAL, self::SYSTEM, self::P3, 'Billing ready', 'CTD §31 "17:15 Billing Ready"'],
        'invoice.posted'      => [self::FINANCIAL, self::ACCOUNTING, self::P3, 'Invoice posted', 'CTD §31 — invoice linkage · EVT-010'],
        'collection.recorded' => [self::FINANCIAL, self::ACCOUNTING, self::P3, 'Payment received', 'EVT-011'],
        'feedback.requested'  => [self::CUSTOMER, self::SYSTEM, self::P3, 'Feedback requested', 'CTD §31 "16:45 Feedback Requested"'],
        'feedback.received'   => [self::CUSTOMER, self::CUSTOMER_SRC, self::P3, 'Feedback received', 'CTD §31 "16:48 Feedback Received"'],
        'compliance.checked'  => [self::COMPLIANCE, self::COMPLIANCE_SRC, self::P3, 'Compliance checked', 'CTD §32 "compliance"'],

        /* ── CTD §34's correction. Any owner. ────────────────────────── */
        'event.corrected'     => [self::OPERATIONAL, self::USER, self::P1, 'Correction', 'CTD §34 "Corrections should create a Correction Event"'],
    ];

    public static function isKnown(string $type): bool
    {
        return isset(self::REGISTRY[$type]);
    }

    public static function categoryFor(string $type): ?string
    {
        return self::REGISTRY[$type][0] ?? null;
    }

    public static function sourceFor(string $type): ?string
    {
        return self::REGISTRY[$type][1] ?? null;
    }

    public static function ownerFor(string $type): ?string
    {
        return self::REGISTRY[$type][2] ?? null;
    }

    /**
     * The sentence a timeline shows.
     *
     * An UNKNOWN type is humanised rather than rejected — "gate.weighbridge"
     * becomes "Gate weighbridge". That is the open column working as intended:
     * a type nobody has registered yet still reads as English on screen, and
     * the registry test is what makes sure somebody registers it.
     */
    public static function label(string $type): string
    {
        return self::REGISTRY[$type][3] ?? ucfirst(str_replace(['.', '_'], ' ', $type));
    }

    public static function citation(string $type): ?string
    {
        return self::REGISTRY[$type][4] ?? null;
    }

    /** @return array<int,string> every type a given section owns */
    public static function ownedBy(string $owner): array
    {
        return array_keys(array_filter(self::REGISTRY, fn (array $r) => $r[2] === $owner));
    }
}
