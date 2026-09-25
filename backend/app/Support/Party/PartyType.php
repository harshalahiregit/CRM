<?php

namespace App\Support\Party;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Purchase\PurchaseContact;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorContact;

/**
 * The vocabulary for "a person at another company".
 *
 * Shared, not task-owned: tasks and projects both assign these people, and the
 * rules about who may be assigned must be one set of rules rather than two that
 * drift.
 *
 * Three modules each grew their own contact record and none of them agree:
 * ClientContact splits first_name/last_name and calls the job `title`;
 * PurchaseContact splits the name too but calls the job `designation` and gates
 * on a `status` string; VendorContact keeps ONE `name` column and has no status
 * at all. Their parents disagree as well — a Client's display name is `company`,
 * both vendors' is `company_name`.
 *
 * Every one of those differences is a place for the task module to get a name
 * wrong, show an inactive contact, or point at the wrong tenant. So they are
 * reconciled exactly once, here, and nothing else in the module is allowed to
 * know that `title` and `designation` are the same idea.
 *
 * Adding a fourth kind of party is a fourth entry in TYPES and nothing else.
 */
final class PartyType
{
    /* Party types — what goes in task_party_assignees.party_type. */
    public const CLIENT_CONTACT   = 'client_contact';
    public const PURCHASE_CONTACT = 'purchase_contact';
    public const VENDOR_CONTACT   = 'vendor_contact';

    /* Org types — the team the party belongs to. */
    public const ORG_CLIENT          = 'client';
    public const ORG_PURCHASE_VENDOR = 'purchase_vendor';
    public const ORG_TPV_VENDOR      = 'tpv_vendor';

    /**
     * Everything the module needs to know about each kind of party.
     *
     *  model      the contact record
     *  org_type   the key for its parent organisation
     *  org_model  the parent organisation's model
     *  org_fk     the column on the contact pointing at that parent
     *  org_label  the parent's display column
     *  label      what a person calls this kind of party on screen
     *  org_label_singular  what a person calls the parent organisation
     */
    public const TYPES = [
        self::CLIENT_CONTACT => [
            'model'     => ClientContact::class,
            'org_type'  => self::ORG_CLIENT,
            'org_model' => Client::class,
            'org_fk'    => 'client_id',
            'org_label' => 'company',
            'label'     => 'Client contact',
            'org_label_singular' => 'Client',
        ],
        self::PURCHASE_CONTACT => [
            'model'     => PurchaseContact::class,
            'org_type'  => self::ORG_PURCHASE_VENDOR,
            'org_model' => PurchaseVendor::class,
            'org_fk'    => 'purchase_vendor_id',
            'org_label' => 'company_name',
            'label'     => 'Vendor contact',
            'org_label_singular' => 'Vendor',
        ],
        self::VENDOR_CONTACT => [
            'model'     => VendorContact::class,
            'org_type'  => self::ORG_TPV_VENDOR,
            'org_model' => Vendor::class,
            'org_fk'    => 'vendor_id',
            'org_label' => 'company_name',
            'label'     => 'TPV contact',
            'org_label_singular' => 'Third-party vendor',
        ],
    ];

    /** org_type → party_type. The picker asks by team, the table stores by person. */
    public const ORG_TO_PARTY = [
        self::ORG_CLIENT          => self::CLIENT_CONTACT,
        self::ORG_PURCHASE_VENDOR => self::PURCHASE_CONTACT,
        self::ORG_TPV_VENDOR      => self::VENDOR_CONTACT,
    ];

    public static function isValidType(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::TYPES);
    }

    public static function isValidOrgType(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::ORG_TO_PARTY);
    }

    /** @return array<string,mixed> */
    public static function config(string $type): array
    {
        return self::TYPES[$type] ?? throw new \InvalidArgumentException("Unknown task party type [$type].");
    }

    public static function label(?string $type): string
    {
        return self::TYPES[$type]['label'] ?? (string) $type;
    }

    public static function orgLabel(?string $orgType): string
    {
        $party = self::ORG_TO_PARTY[$orgType] ?? null;

        return $party ? self::TYPES[$party]['org_label_singular'] : (string) $orgType;
    }

    /**
     * One person's display name, whichever table they came out of.
     *
     * VendorContact has a single `name`; the other two have halves. A contact
     * with neither still has to render as something — a blank chip is a task
     * that looks unassigned — so it falls back to the email, then the id.
     */
    public static function nameOf(object $contact): string
    {
        $name = trim((string) ($contact->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim(implode(' ', array_filter([
            $contact->first_name ?? null,
            $contact->last_name ?? null,
        ])));

        if ($name !== '') {
            return $name;
        }

        return (string) ($contact->email ?: 'Contact #'.($contact->id ?? '?'));
    }

    /** Their job, under whichever column this module chose to call it. */
    public static function roleOf(object $contact): string
    {
        return trim((string) ($contact->designation ?? $contact->title ?? ''));
    }

    /**
     * Is this contact someone work can still be given to?
     *
     * Each table says "no longer with us" differently and two of them do not say
     * it at all, so the check is per-shape rather than per-column: an explicit
     * false/Inactive disqualifies, and a table with no opinion means yes.
     */
    public static function isAssignable(object $contact): bool
    {
        if (array_key_exists('active', $contact->getAttributes()) && ! $contact->active) {
            return false;
        }

        $status = $contact->status ?? null;
        if ($status !== null && strcasecmp((string) $status, 'Inactive') === 0) {
            return false;
        }

        return true;
    }
}
