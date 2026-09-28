<?php

namespace App\Support\Transport;

/**
 * Document types — Step 11 ENUM-006:
 *   lr|ewaybill|invoice|pod|driver_doc|vehicle_doc|insurance|permit|fitness|other
 *
 * This is one of the few enums the canonical registry actually specifies, so it is
 * reproduced exactly. Change rule: "Product+Compliance".
 *
 * ── THE ONE ADDITION, AND ITS AUTHORITY ───────────────────────────────────
 * `delivery_order` is not in ENUM-006. It is here under a written owner approval
 * dated 2026-09-12, recorded against D-41 in docs/transport/registry-defects.md
 * and requested in docs/transport/REQUEST-person3-document-entity.md.
 *
 * The grounds, because an extension to a LOCKED enum should be arguable years
 * from now and not just permitted: STOS-REQ-ORD-006 "Capture DO details" is P0
 * and was unimplementable, since a delivery order had no value to be filed
 * under. It also had no other home — no domain-model row, no table, no enum —
 * and the only alternative, a `transport_delivery_orders` table, gives delivery
 * orders a second home, which Step 9's no-duplicate-business-objects rule
 * forbids and D-41 rejected on exactly that ground.
 *
 * This is the only value added. Everything else is ENUM-006 as written, and
 * anything further needs its own approval.
 *
 * ── THE AWKWARD PART, STATED RATHER THAN SMOOTHED OVER ────────────────────
 * ENUM-006 mixes two axes. `insurance`, `permit` and `fitness` name a specific
 * document; `vehicle_doc` and `driver_doc` name only what the document is ABOUT —
 * which the entity_type column already says. So an insurance certificate is
 * representable twice, and RC / PUC / tax (STOS-FLEET §11 lists all three as
 * distinct vehicle documents) have no value of their own and can only be carried
 * as the catch-all `vehicle_doc`.
 *
 * Two options were available: extend the enum with rc/puc/tax, or use it as
 * written. Extending a LOCKED registry enum is a Product+Compliance change, not a
 * developer's call (FORBID-001), so this uses ENUM-006 as written and records the
 * shortfall. FLEET §11's real requirement — "the system must not assume every
 * vehicle requires exactly the same document set" — is met by making the REQUIRED
 * set configurable per tenant in SNG-TRN-009 step 5, not by inventing enum values.
 *
 * VEHICLE_APPLICABLE / DRIVER_APPLICABLE below are groupings for validation, not
 * new values.
 */
final class TransportDocumentType
{
    public const LR         = 'lr';
    public const EWAYBILL   = 'ewaybill';
    public const INVOICE    = 'invoice';
    public const POD        = 'pod';
    public const DRIVER_DOC = 'driver_doc';
    public const VEHICLE_DOC = 'vehicle_doc';
    public const INSURANCE  = 'insurance';
    public const PERMIT     = 'permit';
    public const FITNESS    = 'fitness';
    public const OTHER      = 'other';

    /** Approved extension to ENUM-006, 2026-09-12. See the note above. */
    public const DELIVERY_ORDER = 'delivery_order';

    /**
     * ── SECOND APPROVED EXTENSION, 2026-09-19 ─────────────────────────────
     * Owner approval, on the same footing as `delivery_order` above and for the
     * reason this file already predicted: ENUM-006 names `insurance`, `permit`
     * and `fitness` but has no value for RC, PUC or road tax, so those three
     * could only be filed as the catch-all `vehicle_doc`.
     *
     * That ambiguity has a concrete cost. STOS-FLEET gates dispatch on FIVE
     * statutory dates — registration, insurance, fitness, permit, PUC — and the
     * verified document is the master for each of them. With RC and PUC both
     * arriving as `vehicle_doc`, nothing can tell which of the five dates a
     * given certificate is supposed to update. The gate would be reading a date
     * nobody could reliably set.
     *
     * Grounds recorded per STOS-CMP §25, STOS-FLEET §11 and STOS-DOC §10, all
     * of which list RC, Insurance, Fitness, Permit, PUC and Tax as distinct
     * statutory vehicle documents.
     */
    public const RC  = 'rc';
    public const PUC = 'puc';
    public const TAX = 'tax';

    /**
     * Driver documents, same approval.
     *
     * DRIVER_APPLICABLE previously offered `fitness` and `permit` — both of
     * which read as VEHICLE documents to anybody using the screen — and the
     * catch-all `driver_doc`. A driving licence, the one document that actually
     * stops a driver being dispatched, had no value of its own.
     *
     * Recorded per STOS-DOC §9, STOS-CMP §22 and STOS-DB §43.
     */
    public const DRIVING_LICENSE      = 'driving_license';
    public const MEDICAL_CERTIFICATE  = 'medical_certificate';
    public const POLICE_VERIFICATION  = 'police_verification';
    public const ID_PROOF             = 'id_proof';
    public const TRAINING_CERTIFICATE = 'training_certificate';
    public const CUSTOMER_QUALIFICATION = 'customer_qualification';

    /**
     * ENUM-006 in registry order, then the one approved addition.
     *
     * Do not reorder: the order is the registry's. Do not extend without an
     * approval of the same kind as the one `delivery_order` carries — appending
     * here is a Product+Compliance decision, not a developer's.
     */
    public const ALL = [
        self::LR, self::EWAYBILL, self::INVOICE, self::POD, self::DRIVER_DOC,
        self::VEHICLE_DOC, self::INSURANCE, self::PERMIT, self::FITNESS, self::OTHER,
        self::DELIVERY_ORDER,
        // Approved 2026-09-19 — see the note on the constants.
        self::RC, self::PUC, self::TAX,
        self::DRIVING_LICENSE, self::MEDICAL_CERTIFICATE, self::POLICE_VERIFICATION,
        self::ID_PROOF, self::TRAINING_CERTIFICATE, self::CUSTOMER_QUALIFICATION,
    ];

    /** Types that may be filed against a vehicle (FLEET §11: RC, insurance, fitness, PUC, permit, tax). */
    public const VEHICLE_APPLICABLE = [
        self::RC, self::INSURANCE, self::FITNESS, self::PERMIT, self::PUC, self::TAX,
        self::VEHICLE_DOC, self::OTHER,
    ];

    /**
     * The five that gate dispatch, mapped to the date each one sets on the
     * vehicle. `tax` is a statutory document but is NOT a dispatch gate, so it
     * is filed and tracked without blocking a truck.
     */
    public const GATES_DISPATCH = [
        self::RC        => 'registration_expiry',
        self::INSURANCE => 'insurance_expiry',
        self::FITNESS   => 'fitness_expiry',
        self::PERMIT    => 'permit_expiry',
        self::PUC       => 'puc_expiry',
    ];

    /** Types that may be filed against a driver (CMP §22: licence, training, medical/fitness). */
    public const DRIVER_APPLICABLE = [
        self::DRIVING_LICENSE, self::MEDICAL_CERTIFICATE, self::POLICE_VERIFICATION,
        self::ID_PROOF, self::TRAINING_CERTIFICATE, self::CUSTOMER_QUALIFICATION,
        self::DRIVER_DOC, self::OTHER,

        // DEPRECATED for drivers, still accepted.
        //
        // Before the 2026-09-19 approval a driver's medical fitness had to be
        // filed as `fitness` — the same value a vehicle's fitness certificate
        // uses — because there was no `medical_certificate`. Tenants have that
        // configured as a required driver document today, and documents are
        // already filed under it.
        //
        // Removing them would throw "Fitness certificate cannot be required of
        // a driver" at tenants whose policy was valid when they set it, and
        // orphan the documents already filed. New uploads should use
        // `medical_certificate`; these stay readable.
        self::FITNESS, self::PERMIT,
    ];

    /**
     * Types that may be filed against a consignment.
     *
     * D-41 ruled that LR and DO stay documents rather than getting tables of
     * their own, which makes the consignment the thing they are filed against.
     * These are the shipment's own paperwork — what travels with the goods and
     * what proves they arrived.
     *
     * Deliberately excludes insurance, permit, fitness, vehicle_doc and
     * driver_doc: those describe a vehicle or a driver, and both outlive the
     * consignment they happened to be carrying. Filing a fitness certificate
     * against a shipment would make it expire when the shipment closed.
     */
    public const CONSIGNMENT_APPLICABLE = [
        self::LR, self::DELIVERY_ORDER, self::EWAYBILL, self::INVOICE, self::POD, self::OTHER,
    ];

    public const LABELS = [
        self::LR          => 'LR / Bilty',
        self::EWAYBILL    => 'E-Way Bill',
        self::INVOICE     => 'Invoice',
        self::POD         => 'Proof of delivery',
        self::DRIVER_DOC  => 'Driver document',
        self::VEHICLE_DOC => 'Vehicle document',
        self::INSURANCE   => 'Insurance',
        self::PERMIT      => 'Permit',
        self::RC          => 'Registration certificate (RC)',
        self::PUC         => 'Pollution certificate (PUC)',
        self::TAX         => 'Road tax',
        self::DRIVING_LICENSE       => 'Driving licence',
        self::MEDICAL_CERTIFICATE   => 'Medical certificate',
        self::POLICE_VERIFICATION   => 'Police verification',
        self::ID_PROOF              => 'ID proof',
        self::TRAINING_CERTIFICATE  => 'Training certificate',
        self::CUSTOMER_QUALIFICATION => 'Customer site qualification',
        self::FITNESS     => 'Fitness certificate',
        self::OTHER       => 'Other',
        self::DELIVERY_ORDER => 'Delivery Order',
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /**
     * Which types may be filed against this kind of record.
     *
     * Was a two-way ternary — driver, or else vehicle. That "or else" made the
     * vehicle set the answer for every entity that was not a driver, so adding
     * consignment to TransportDocumentEntity would have offered a shipment
     * insurance and fitness certificates and refused it an LR, which is the
     * opposite of D-41. The branch is now explicit per entity.
     *
     * An unknown entity returns nothing rather than a default set: an entity
     * nobody has decided about should refuse documents and say so, not quietly
     * inherit whichever list happened to be last in the ternary. CUSTOMER is
     * declared in TransportDocumentEntity but has no ticket, and so lands here
     * on purpose until one exists.
     *
     * @return string[]
     */
    public static function forEntity(string $entityType): array
    {
        return match ($entityType) {
            TransportDocumentEntity::DRIVER      => self::DRIVER_APPLICABLE,
            TransportDocumentEntity::VEHICLE     => self::VEHICLE_APPLICABLE,
            TransportDocumentEntity::CONSIGNMENT => self::CONSIGNMENT_APPLICABLE,
            default                              => [],
        };
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
