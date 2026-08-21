<?php

namespace App\Support\Customer;

/**
 * The option lists behind Customer classification and contact depth.
 *
 * These are the document's defaults (§11, §13), not a closed set: they seed the
 * dropdowns, and a tenant can edit its own lists from Settings. Kept here rather
 * than as a database enum precisely so a tenant adding "NGO" or "Distributor"
 * does not need a migration.
 *
 * Nothing validates against these lists on write. A value that was valid when it
 * was saved must stay readable after an admin edits the list, and a hard
 * whitelist would turn every list edit into a data-repair job.
 */
class CustomerOptions
{
    /** §13 — what kind of organisation this is. Restores the old CRM's client_type. */
    public const TYPES = [
        'Corporate', 'SME', 'Government', 'PSU', 'MNC', 'Startup', 'Individual', 'Partner', 'Other',
    ];

    /** §13 — how much the relationship matters. Drives nothing yet; display and filter only. */
    public const TIERS = ['Strategic', 'Key Account', 'Standard', 'Small'];

    /** §13 — deliberately short and editable; every business has its own list. */
    public const INDUSTRIES = [
        'Pharma', 'Manufacturing', 'Logistics', 'Construction', 'IT', 'Retail',
        'Healthcare', 'Education', 'Financial Services', 'Energy', 'Other',
    ];

    /** §11 — what a contact is TO US, as distinct from their job title. */
    public const CONTACT_ROLES = [
        'CEO / Management', 'Procurement', 'Finance', 'Accounts Payable',
        'Operations', 'HSE', 'Technical', 'Project Manager', 'Billing', 'Commercial',
    ];

    /** §11 — how much sway they hold over a decision. */
    public const INFLUENCE = ['High', 'Medium', 'Low'];

    /**
     * Lifecycle. Not in the document — added because Health (§8) is dishonest
     * without it: a churned customer has no open tickets and no overdue
     * invoices, which reads as a perfect score unless something says otherwise.
     */
    public const LIFECYCLE = ['Prospect', 'Active', 'Dormant', 'Churned'];

    /**
     * Payment terms. Health scores payment behaviour, and "paid on day 40" is
     * excellent on Net 45 and delinquent on Net 15 — the agreed terms are what
     * make the difference meaningful.
     */
    public const PAYMENT_TERMS = [
        'Due on Receipt', 'Net 7', 'Net 15', 'Net 30', 'Net 45', 'Net 60', 'Net 90',
    ];

    /** §16 — note taxonomy. `visibility` already governs which staff can read one. */
    public const NOTE_TYPES = [
        'General', 'Customer', 'Internal', 'Meeting', 'Commercial', 'Service', 'Escalation',
    ];

    /** Everything the settings screen and the forms need, in one payload. */
    public static function all(): array
    {
        return [
            'customer_type' => self::TYPES,
            'customer_tier' => self::TIERS,
            'industry'      => self::INDUSTRIES,
            'contact_role'  => self::CONTACT_ROLES,
            'influence'     => self::INFLUENCE,
            'lifecycle'     => self::LIFECYCLE,
            'payment_terms' => self::PAYMENT_TERMS,
            'note_type'     => self::NOTE_TYPES,
        ];
    }

    /** Days implied by a payment term, for Health's payment-behaviour score. */
    public static function termDays(?string $term): ?int
    {
        return match ($term) {
            'Due on Receipt' => 0,
            'Net 7'  => 7,
            'Net 15' => 15,
            'Net 30' => 30,
            'Net 45' => 45,
            'Net 60' => 60,
            'Net 90' => 90,
            default  => null,
        };
    }
}
