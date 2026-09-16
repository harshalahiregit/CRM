<?php

namespace App\Support\Transport;

/**
 * What kind of exception this is — STOS-OPS §88's eight categories, verbatim.
 *
 * §88 lists eight with a one-line gloss each, and those glosses are kept here
 * because they are the only definition the package gives. Step 11 has no
 * category enum at all — ENUM-003 covers severity and ENUM-004 covers status,
 * and nothing covers category, although OPS §87 lists it among the twelve
 * fields an exception "must contain". Recorded as D-37.
 *
 * So this enum has no LOCKED registry row to defer to. OPS §88 governs by
 * default: it is the only list, it is in the document that owns the exception
 * engine, and inventing a shorter one would lose categories the business
 * already names.
 *
 * ── ALL EIGHT ARE SELECTABLE, AND THAT IS THE POINT ──────────────────────
 * Unlike the status enum, nothing here is unreachable. A category is a label a
 * person chooses when raising an exception; none of them needs a data model
 * behind it. `temperature` and `fleet` describe exceptions that no automatic
 * source can yet raise — but a controller phoning in a breakdown can still
 * classify it correctly, and refusing them would make the vocabulary lie about
 * what operations actually sees.
 *
 * What is NOT here is any automatic mapping from a category to an owner. OPS
 * §90 wants exactly that ("assign an owner based on: category; branch; role;
 * organization; escalation matrix") and the owner ruled it out for this ticket
 * (Q6) because branch, role hierarchy and the matrix have no data model.
 */
final class ExceptionCategory
{
    /** §88: "Driver/vehicle shortage." */
    public const RESOURCE = 'resource';

    /** §88: "Invalid document." */
    public const COMPLIANCE = 'compliance';

    /** §88: "Delay/deviation." The transit category — BR-P0-010's own. */
    public const OPERATIONAL = 'operational';

    /** §88: "Excursion/Genset." */
    public const TEMPERATURE = 'temperature';

    /** §88: "Revenue leakage/billing block." */
    public const FINANCIAL = 'financial';

    /** §88: "Complaint/service issue." */
    public const CUSTOMER = 'customer';

    /** §88: "Breakdown/maintenance." STOS-REQ-OPS-012's own. */
    public const FLEET = 'fleet';

    /** §88: "Missing/rejected document." */
    public const DOCUMENTATION = 'documentation';

    /** §88's order, which is the document's order. */
    public const ALL = [
        self::RESOURCE,
        self::COMPLIANCE,
        self::OPERATIONAL,
        self::TEMPERATURE,
        self::FINANCIAL,
        self::CUSTOMER,
        self::FLEET,
        self::DOCUMENTATION,
    ];

    /** §88's glosses, verbatim. The only definition the package gives. */
    public const DEFINITIONS = [
        self::RESOURCE      => 'Driver/vehicle shortage',
        self::COMPLIANCE    => 'Invalid document',
        self::OPERATIONAL   => 'Delay/deviation',
        self::TEMPERATURE   => 'Excursion/Genset',
        self::FINANCIAL     => 'Revenue leakage/billing block',
        self::CUSTOMER      => 'Complaint/service issue',
        self::FLEET         => 'Breakdown/maintenance',
        self::DOCUMENTATION => 'Missing/rejected document',
    ];

    public const LABELS = [
        self::RESOURCE      => 'Resource',
        self::COMPLIANCE    => 'Compliance',
        self::OPERATIONAL   => 'Operational',
        self::TEMPERATURE   => 'Temperature',
        self::FINANCIAL     => 'Financial',
        self::CUSTOMER      => 'Customer',
        self::FLEET         => 'Fleet',
        self::DOCUMENTATION => 'Documentation',
    ];

    /**
     * Which RTM requirement would raise each category automatically, once the
     * ticket that owns it exists. Recorded so that a later ticket wiring an
     * automatic source knows which category it must use, rather than picking a
     * ninth one and fragmenting the vocabulary.
     *
     * A category absent from this map has no automatic source in any document —
     * it is raised by a person, always.
     */
    public const AUTOMATIC_SOURCE = [
        self::RESOURCE    => 'STOS-REQ-PLN-009 — vehicle idle due to driver shortage (P0, FLEET, not built)',
        self::OPERATIONAL => 'BR-P0-010 — critical route deviation/idle from a GPS event (SNG-TRN-020, P1)',
        self::TEMPERATURE => 'STOS-REQ-TEL-006/007 — temperature excursion, generator OFF (P0, QC, no telemetry model)',
        self::FINANCIAL   => 'BR-P0-008/009 — fuel variance, duplicate toll (SNG-TRN-012, not built)',
        self::FLEET       => 'STOS-REQ-MNT-010 — overdue maintenance (P0, FLEET, no maintenance model)',
    ];

    public static function isValid(string $category): bool
    {
        return in_array($category, self::ALL, true);
    }

    public static function label(string $category): string
    {
        return self::LABELS[$category] ?? $category;
    }

    public static function definition(string $category): ?string
    {
        return self::DEFINITIONS[$category] ?? null;
    }
}
