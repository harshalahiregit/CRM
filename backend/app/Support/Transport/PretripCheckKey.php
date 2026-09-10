<?php

namespace App\Support\Transport;

/**
 * The pre-trip check vocabulary — STOS-OPS §28, extended by the four other
 * documents that name checklist items.
 *
 * ── DECLARE ALL, WIRE ONLY REACHABLE ──────────────────────────────────────
 * Twenty-three keys are declared; FIVE are generated. That ratio is not a
 * shortfall, it is the finding: the package names a great many checks and stores
 * the inputs for very few of them. Ruled by the owner on 2026-09-09 (Q2 and Q5)
 * — declare the checks, leave them unreachable — which is the same treatment
 * trailer and GPS received in ticket 009.
 *
 * Declaring them costs one constant each and buys three things: the key is fixed
 * before anything writes it, an audit can prove no named item was dropped, and
 * the ticket that later builds tyres or genset inherits the name instead of
 * inventing a second one.
 *
 * ── WHERE THE ITEMS COME FROM ─────────────────────────────────────────────
 *   OPS §28   5 categories, 14 items. "Minimum categories" — a floor, so the
 *             other four lists extend it rather than contradicting it.
 *   BRW-047   13 dispatch-checklist inputs; adds customer requirement, fuel,
 *             required approvals.
 *   OPS §35   9 "Possible checks"; adds lights, brakes, engine, safety equipment.
 *   CMP §157  6 readiness inputs; adds service requirement.
 *   FRS TRP-P0-005  adds trailer, safety.
 *
 * SOURCES below records which document named each key, so a reviewer can check
 * the derivation without re-reading five documents.
 *
 * ── WHY ONLY FIVE ARE GENERATED ───────────────────────────────────────────
 * Every generated check reads something that actually exists in the schema:
 * the order's status, the trip's assignment rows, and the two eligibility
 * services' verdicts over transport_documents. Everything else would have to
 * read a column no migration in this repository creates and no entity in Step
 * 11's twenty defines — maintenance state, tyre condition, genset hours, a
 * temperature set point, a training record, a rate card, a handover receipt.
 *
 * That is not a small list, and it is stated plainly rather than buried: OUT OF
 * THE BOX, THIS CHECKLIST VERIFIES COMMERCIAL APPROVAL, CREW ASSIGNMENT AND
 * DOCUMENT VALIDITY. It does not verify the physical condition of the vehicle.
 * PretripScope::EXCLUDED carries the reason for each one.
 *
 * ── WHAT THE FIVE ARE FOR ─────────────────────────────────────────────────
 * They are not a duplicate of the allocation eligibility check. FLEET §16 is the
 * distinction: "Available is not Eligible; Eligible is not Ready." Allocation
 * asked whether this crew COULD be assigned. Pre-trip asks whether they are
 * still fit to leave — and the interval between the two is exactly where an
 * insurance policy lapses or a licence expires. Re-running the checks at
 * departure is the substance of RTM OPS-005 and CMP §159's dispatch gate.
 */
final class PretripCheckKey
{
    /* ── Categories, OPS §28's five, in the document's order ──────────── */
    public const CATEGORY_COMMERCIAL = 'commercial';
    public const CATEGORY_DRIVER     = 'driver';
    public const CATEGORY_VEHICLE    = 'vehicle';
    public const CATEGORY_REEFER     = 'reefer';
    public const CATEGORY_DOCUMENTS  = 'documents';

    public const CATEGORIES = [
        self::CATEGORY_COMMERCIAL, self::CATEGORY_DRIVER, self::CATEGORY_VEHICLE,
        self::CATEGORY_REEFER, self::CATEGORY_DOCUMENTS,
    ];

    public const CATEGORY_LABELS = [
        self::CATEGORY_COMMERCIAL => 'Commercial',
        self::CATEGORY_DRIVER     => 'Driver',
        self::CATEGORY_VEHICLE    => 'Vehicle',
        self::CATEGORY_REEFER     => 'Reefer',
        self::CATEGORY_DOCUMENTS  => 'Documents',
    ];

    /* ── Commercial ───────────────────────────────────────────────────── */

    /** OPS §28 "Order approved"; BRW-047 "order approval". GENERATED. */
    public const ORDER_APPROVED = 'commercial.order_approved';

    /** OPS §28 "Rate available". Needs transport_rates — SNG-TRN-005. */
    public const RATE_AVAILABLE = 'commercial.rate_available';

    /** BRW-047, CMP §157 "customer requirement". No customer-requirement model. */
    public const CUSTOMER_REQUIREMENT = 'commercial.customer_requirement';

    /** CMP §157 "Service Requirement". order.special_requirements is free text. */
    public const SERVICE_REQUIREMENT = 'commercial.service_requirement';

    /** BRW-047 "required approvals". No approval-request entity in Step 11. */
    public const REQUIRED_APPROVALS = 'commercial.required_approvals';

    /* ── Driver ───────────────────────────────────────────────────────── */

    /** OPS §28 "Assigned"; BRW-047 "driver". GENERATED. */
    public const DRIVER_ASSIGNED = 'driver.assigned';

    /** OPS §28 "Valid documents"; BRW-047 "driver compliance". GENERATED. */
    public const DRIVER_DOCUMENTS = 'driver.documents_valid';

    /** OPS §28 "Required training"; CMP §157 "Training". No training model. */
    public const DRIVER_TRAINING = 'driver.training';

    /* ── Vehicle ──────────────────────────────────────────────────────── */

    /** OPS §28 "Assigned"; BRW-047 "vehicle". GENERATED. */
    public const VEHICLE_ASSIGNED = 'vehicle.assigned';

    /** OPS §28 "Compliance valid"; BRW-047 "vehicle compliance". GENERATED. */
    public const VEHICLE_COMPLIANCE = 'vehicle.compliance_valid';

    /** OPS §28 "Maintenance acceptable"; BRW-044/047. No maintenance entity. */
    public const VEHICLE_MAINTENANCE = 'vehicle.maintenance';

    /** OPS §28 "Tyres acceptable"; BRW-045/047; OPS §35. No tyre entity. */
    public const VEHICLE_TYRES = 'vehicle.tyres';

    /** OPS §35 "lights". */
    public const VEHICLE_LIGHTS = 'vehicle.lights';

    /** OPS §35 "brakes". */
    public const VEHICLE_BRAKES = 'vehicle.brakes';

    /** OPS §35 "engine". */
    public const VEHICLE_ENGINE = 'vehicle.engine';

    /** OPS §35, BRW-047 "fuel". No fuel model until SNG-TRN-012. */
    public const VEHICLE_FUEL = 'vehicle.fuel';

    /** OPS §35 "safety equipment"; FRS TRP-P0-005 "safety". */
    public const VEHICLE_SAFETY = 'vehicle.safety_equipment';

    /** FRS TRP-P0-005 "trailer". No trailer entity exists in Step 11. */
    public const VEHICLE_TRAILER = 'vehicle.trailer';

    /* ── Reefer ───────────────────────────────────────────────────────── */

    /** OPS §28 "Genset operational"; BRW-053; OPS §35; FRS TRP-P0-005. */
    public const REEFER_GENSET = 'reefer.genset';

    /** OPS §28 "Temperature set point"; BRW-047; OPS §35. */
    public const REEFER_TEMPERATURE = 'reefer.temperature';

    /** OPS §28 "Equipment readiness". */
    public const REEFER_EQUIPMENT = 'reefer.equipment';

    /* ── Documents ────────────────────────────────────────────────────── */

    /**
     * OPS §28 "Required documents ready"; BRW-047 "documents".
     *
     * NEVER GENERATED, and deliberately so — this is a cross-reference, not an
     * omission. Under Q3's ruling the required-document set means the tenant's
     * configured vehicle.required_documents and driver.required_documents, and
     * VehicleEligibilityService::documentVerdict already evaluates BOTH halves
     * of DOC §16's distinction in one place: nothing on file has lapsed
     * (Missing) and everything configured is present (Required).
     *
     * Generating a third check that re-states the other two would report the
     * same fact twice and let the two disagree. PretripScope::OPS_28_DISPOSITION
     * marks it 'cross_ref' so the requirement is visibly met rather than lost.
     */
    public const DOCUMENTS_REQUIRED = 'documents.required_ready';

    /** OPS §28 "Driver handover completed"; OPS §32; BRW-059/060; RTM DOC-005. */
    public const DOCUMENTS_HANDOVER = 'documents.handover';

    /* ── The vocabulary ───────────────────────────────────────────────── */

    /**
     * The five checks this ticket generates and evaluates.
     *
     * Order matters: it is the order the checklist is built in and displayed in,
     * and it follows OPS §28's own category order.
     */
    public const GENERATED = [
        self::ORDER_APPROVED,
        self::DRIVER_ASSIGNED,
        self::DRIVER_DOCUMENTS,
        self::VEHICLE_ASSIGNED,
        self::VEHICLE_COMPLIANCE,
    ];

    /** Declared to match the documents; nothing in this ticket produces them. */
    public const UNREACHABLE = [
        self::RATE_AVAILABLE, self::CUSTOMER_REQUIREMENT, self::SERVICE_REQUIREMENT,
        self::REQUIRED_APPROVALS, self::DRIVER_TRAINING, self::VEHICLE_MAINTENANCE,
        self::VEHICLE_TYRES, self::VEHICLE_LIGHTS, self::VEHICLE_BRAKES,
        self::VEHICLE_ENGINE, self::VEHICLE_FUEL, self::VEHICLE_SAFETY,
        self::VEHICLE_TRAILER, self::REEFER_GENSET, self::REEFER_TEMPERATURE,
        self::REEFER_EQUIPMENT, self::DOCUMENTS_REQUIRED, self::DOCUMENTS_HANDOVER,
    ];

    public const ALL = [
        self::ORDER_APPROVED, self::RATE_AVAILABLE, self::CUSTOMER_REQUIREMENT,
        self::SERVICE_REQUIREMENT, self::REQUIRED_APPROVALS,
        self::DRIVER_ASSIGNED, self::DRIVER_DOCUMENTS, self::DRIVER_TRAINING,
        self::VEHICLE_ASSIGNED, self::VEHICLE_COMPLIANCE, self::VEHICLE_MAINTENANCE,
        self::VEHICLE_TYRES, self::VEHICLE_LIGHTS, self::VEHICLE_BRAKES,
        self::VEHICLE_ENGINE, self::VEHICLE_FUEL, self::VEHICLE_SAFETY,
        self::VEHICLE_TRAILER,
        self::REEFER_GENSET, self::REEFER_TEMPERATURE, self::REEFER_EQUIPMENT,
        self::DOCUMENTS_REQUIRED, self::DOCUMENTS_HANDOVER,
    ];

    public const LABELS = [
        self::ORDER_APPROVED       => 'Order approved',
        self::RATE_AVAILABLE       => 'Rate available',
        self::CUSTOMER_REQUIREMENT => 'Customer requirement',
        self::SERVICE_REQUIREMENT  => 'Service requirement',
        self::REQUIRED_APPROVALS   => 'Required approvals',
        self::DRIVER_ASSIGNED      => 'Driver assigned',
        self::DRIVER_DOCUMENTS     => 'Driver documents valid',
        self::DRIVER_TRAINING      => 'Required training',
        self::VEHICLE_ASSIGNED     => 'Vehicle assigned',
        self::VEHICLE_COMPLIANCE   => 'Vehicle compliance valid',
        self::VEHICLE_MAINTENANCE  => 'Maintenance acceptable',
        self::VEHICLE_TYRES        => 'Tyres acceptable',
        self::VEHICLE_LIGHTS       => 'Lights',
        self::VEHICLE_BRAKES       => 'Brakes',
        self::VEHICLE_ENGINE       => 'Engine',
        self::VEHICLE_FUEL         => 'Fuel',
        self::VEHICLE_SAFETY       => 'Safety equipment',
        self::VEHICLE_TRAILER      => 'Trailer',
        self::REEFER_GENSET        => 'Genset operational',
        self::REEFER_TEMPERATURE   => 'Temperature set point',
        self::REEFER_EQUIPMENT     => 'Equipment readiness',
        self::DOCUMENTS_REQUIRED   => 'Required documents ready',
        self::DOCUMENTS_HANDOVER   => 'Driver handover completed',
    ];

    /** Which document named each key. Derivation, kept checkable. */
    public const SOURCES = [
        self::ORDER_APPROVED       => 'OPS §28 Commercial; BRW-047',
        self::RATE_AVAILABLE       => 'OPS §28 Commercial',
        self::CUSTOMER_REQUIREMENT => 'BRW-047; CMP §157',
        self::SERVICE_REQUIREMENT  => 'CMP §157',
        self::REQUIRED_APPROVALS   => 'BRW-047',
        self::DRIVER_ASSIGNED      => 'OPS §28 Driver; BRW-047',
        self::DRIVER_DOCUMENTS     => 'OPS §28 Driver; BRW-047; CMP §157',
        self::DRIVER_TRAINING      => 'OPS §28 Driver; CMP §157',
        self::VEHICLE_ASSIGNED     => 'OPS §28 Vehicle; BRW-047',
        self::VEHICLE_COMPLIANCE   => 'OPS §28 Vehicle; BRW-047; CMP §157',
        self::VEHICLE_MAINTENANCE  => 'OPS §28 Vehicle; BRW-044; BRW-047',
        self::VEHICLE_TYRES        => 'OPS §28 Vehicle; BRW-045; BRW-047; OPS §35; FRS TRP-P0-005',
        self::VEHICLE_LIGHTS       => 'OPS §35',
        self::VEHICLE_BRAKES       => 'OPS §35',
        self::VEHICLE_ENGINE       => 'OPS §35',
        self::VEHICLE_FUEL         => 'OPS §35; BRW-047',
        self::VEHICLE_SAFETY       => 'OPS §35; FRS TRP-P0-005',
        self::VEHICLE_TRAILER      => 'FRS TRP-P0-005',
        self::REEFER_GENSET        => 'OPS §28 Reefer; BRW-053; OPS §35; FRS TRP-P0-005',
        self::REEFER_TEMPERATURE   => 'OPS §28 Reefer; BRW-047; OPS §35',
        self::REEFER_EQUIPMENT     => 'OPS §28 Reefer',
        self::DOCUMENTS_REQUIRED   => 'OPS §28 Documents; BRW-047',
        self::DOCUMENTS_HANDOVER   => 'OPS §28 Documents; OPS §32; BRW-059/060; RTM DOC-005',
    ];

    /** key => category. Every key belongs to exactly one OPS §28 category. */
    public const CATEGORY_OF = [
        self::ORDER_APPROVED       => self::CATEGORY_COMMERCIAL,
        self::RATE_AVAILABLE       => self::CATEGORY_COMMERCIAL,
        self::CUSTOMER_REQUIREMENT => self::CATEGORY_COMMERCIAL,
        self::SERVICE_REQUIREMENT  => self::CATEGORY_COMMERCIAL,
        self::REQUIRED_APPROVALS   => self::CATEGORY_COMMERCIAL,
        self::DRIVER_ASSIGNED      => self::CATEGORY_DRIVER,
        self::DRIVER_DOCUMENTS     => self::CATEGORY_DRIVER,
        self::DRIVER_TRAINING      => self::CATEGORY_DRIVER,
        self::VEHICLE_ASSIGNED     => self::CATEGORY_VEHICLE,
        self::VEHICLE_COMPLIANCE   => self::CATEGORY_VEHICLE,
        self::VEHICLE_MAINTENANCE  => self::CATEGORY_VEHICLE,
        self::VEHICLE_TYRES        => self::CATEGORY_VEHICLE,
        self::VEHICLE_LIGHTS       => self::CATEGORY_VEHICLE,
        self::VEHICLE_BRAKES       => self::CATEGORY_VEHICLE,
        self::VEHICLE_ENGINE       => self::CATEGORY_VEHICLE,
        self::VEHICLE_FUEL         => self::CATEGORY_VEHICLE,
        self::VEHICLE_SAFETY       => self::CATEGORY_VEHICLE,
        self::VEHICLE_TRAILER      => self::CATEGORY_VEHICLE,
        self::REEFER_GENSET        => self::CATEGORY_REEFER,
        self::REEFER_TEMPERATURE   => self::CATEGORY_REEFER,
        self::REEFER_EQUIPMENT     => self::CATEGORY_REEFER,
        self::DOCUMENTS_REQUIRED   => self::CATEGORY_DOCUMENTS,
        self::DOCUMENTS_HANDOVER   => self::CATEGORY_DOCUMENTS,
    ];

    public static function isValid(string $key): bool
    {
        return in_array($key, self::ALL, true);
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    public static function categoryOf(string $key): ?string
    {
        return self::CATEGORY_OF[$key] ?? null;
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? $category;
    }

    public static function isGenerated(string $key): bool
    {
        return in_array($key, self::GENERATED, true);
    }
}
