<?php

namespace App\Support\Transport;

/**
 * Document types — Step 11 ENUM-006, verbatim:
 *   lr|ewaybill|invoice|pod|driver_doc|vehicle_doc|insurance|permit|fitness|other
 *
 * This is one of the few enums the canonical registry actually specifies, so it is
 * reproduced exactly and not extended. Change rule: "Product+Compliance".
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

    /** ENUM-006 in registry order. Do not reorder, do not extend. */
    public const ALL = [
        self::LR, self::EWAYBILL, self::INVOICE, self::POD, self::DRIVER_DOC,
        self::VEHICLE_DOC, self::INSURANCE, self::PERMIT, self::FITNESS, self::OTHER,
    ];

    /** Types that may be filed against a vehicle (FLEET §11: RC, insurance, fitness, PUC, permit, tax). */
    public const VEHICLE_APPLICABLE = [
        self::VEHICLE_DOC, self::INSURANCE, self::PERMIT, self::FITNESS, self::OTHER,
    ];

    /** Types that may be filed against a driver (CMP §22: licence, training, medical/fitness). */
    public const DRIVER_APPLICABLE = [
        self::DRIVER_DOC, self::FITNESS, self::PERMIT, self::OTHER,
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
        self::FITNESS     => 'Fitness certificate',
        self::OTHER       => 'Other',
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /** @return string[] */
    public static function forEntity(string $entityType): array
    {
        return $entityType === TransportDocumentEntity::DRIVER
            ? self::DRIVER_APPLICABLE
            : self::VEHICLE_APPLICABLE;
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
