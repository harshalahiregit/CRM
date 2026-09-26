<?php

namespace App\Services\Shared;

use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Shared\MeetingVisibility;
use Illuminate\Support\Facades\DB;

/**
 * The four columns of the attendance sheet, and who can go in each.
 *
 * MeetingParticipantDirectory answers a different question — "everyone who
 * could be invited", as one flat searchable list. Useful, but it lists a vendor
 * as a single row carrying the COMPANY: name = "Acme Fabrication", designation
 * empty. So a meeting minuted "Acme Fabrication attended", which is not a
 * person, cannot be marked present or absent, and tells a reader nothing about
 * who was actually in the room.
 *
 * Here the question is asked in two steps, the way somebody filling in an
 * attendance sheet asks it: which company, then which of their people. Those
 * people are the ones already registered against that company — its contacts
 * and its workforce — so the name and the designation come from the record
 * rather than from whoever is typing.
 *
 * ── Four parties, three of them external ────────────────────────────────
 *   organiser — the internal team, straight from the staff directory
 *   client    — a customer, then that customer's contacts
 *   vendor    — a Purchase vendor, then its contacts and workers
 *   tpv       — a third-party vendor, then its contacts and workers
 *
 * ── On the module boundary ──────────────────────────────────────────────
 * Purchase and TPV keep separate vendor masters and neither reads the other's
 * tables. That rule is about ownership of records: a Purchase screen must not
 * write, edit or count a TPV vendor. An attendance sheet does none of those —
 * it reads a name and a designation to record who sat in a meeting, and both
 * columns exist precisely because one meeting can have both kinds of company in
 * the room. Nothing is joined, nothing is written back, and the participant row
 * keeps the origin as an opaque string (see the party migration) so no foreign
 * key crosses either way.
 */
class MeetingPartyDirectory
{
    public const ORGANISER = 'organiser';

    public const CLIENT = 'client';

    public const VENDOR = 'vendor';

    public const TPV = 'tpv';

    /** The columns, left to right, as the grid renders them. */
    public const PARTIES = [
        ['key' => self::ORGANISER, 'label' => 'Organiser (Internal Team)', 'side' => 'internal', 'picks_entity' => false],
        ['key' => self::CLIENT,    'label' => 'Client',                    'side' => 'external', 'picks_entity' => true],
        ['key' => self::VENDOR,    'label' => 'Vendor',                    'side' => 'external', 'picks_entity' => true],
        ['key' => self::TPV,       'label' => 'Third-Party Vendor',        'side' => 'external', 'picks_entity' => true],
    ];

    public static function isParty(?string $party): bool
    {
        return in_array($party, [self::ORGANISER, self::CLIENT, self::VENDOR, self::TPV], true);
    }

    /**
     * The companies selectable in each external column, plus the internal team
     * (which needs no company step — you pick the person directly).
     *
     * @return array<string,mixed>
     */
    public function parties(int $tenantId): array
    {
        return array_map(fn (array $p) => $p + [
            'entities' => match ($p['key']) {
                self::CLIENT => $this->clients($tenantId),
                self::VENDOR => $this->purchaseVendors($tenantId),
                self::TPV    => $this->tpvVendors($tenantId),
                default      => [],
            },
            // The organiser column has no company to choose, so its people come
            // back with the list itself rather than on a second request.
            'people' => $p['key'] === self::ORGANISER ? $this->organisers($tenantId) : [],
        ], self::PARTIES);
    }

    /**
     * One company's registered people — contacts first, then workforce.
     *
     * @return list<array<string,mixed>>
     */
    public function people(int $tenantId, string $party, int $entityId): array
    {
        return match ($party) {
            self::ORGANISER => $this->organisers($tenantId),
            self::CLIENT    => $this->clientContacts($tenantId, $entityId),
            self::VENDOR    => $this->purchaseVendorPeople($tenantId, $entityId),
            self::TPV       => $this->tpvVendorPeople($tenantId, $entityId),
            default         => [],
        };
    }

    /** Internal staff — name and designation come from their own user record. */
    private function organisers(int $tenantId): array
    {
        return User::where('tenant_id', $tenantId)
            ->whereIn('role', MeetingVisibility::INTERNAL_ROLES)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'designation', 'role'])
            ->map(fn ($u) => [
                'ref'          => 'user:'.$u->id,
                'user_id'      => $u->id,
                'name'         => $u->name,
                // A designation is what the grid shows under the name, so fall
                // back to the role rather than leaving the line blank.
                'designation'  => $u->designation ?: $this->humanise($u->role),
                'email'        => $u->email,
                'organisation' => null,
            ])->values()->all();
    }

    // ── Entity lists ────────────────────────────────────────────────────

    private function clients(int $tenantId): array
    {
        return DB::table('clients')
            ->where('tenant_id', $tenantId)
            ->orderBy('company')
            ->get(['id', 'company'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->company])
            ->filter(fn ($c) => (string) $c['name'] !== '')
            ->values()->all();
    }

    private function purchaseVendors(int $tenantId): array
    {
        return PurchaseVendor::where('tenant_id', $tenantId)
            ->orderBy('company_name')
            ->get(['id', 'company_name'])
            ->map(fn ($v) => ['id' => $v->id, 'name' => $v->company_name])
            ->values()->all();
    }

    private function tpvVendors(int $tenantId): array
    {
        return Vendor::where('tenant_id', $tenantId)
            ->orderBy('company_name')
            ->get(['id', 'company_name'])
            ->map(fn ($v) => ['id' => $v->id, 'name' => $v->company_name])
            ->values()->all();
    }

    // ── People inside one entity ────────────────────────────────────────

    private function clientContacts(int $tenantId, int $clientId): array
    {
        $org = DB::table('clients')->where('tenant_id', $tenantId)->where('id', $clientId)->value('company');

        return DB::table('client_contacts')
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('active', true)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'title', 'email'])
            ->map(fn ($c) => [
                'ref'          => 'client_contact:'.$c->id,
                'user_id'      => null,
                'name'         => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
                // On a client contact the job title is called `title`.
                'designation'  => $c->title,
                'email'        => $c->email,
                'organisation' => $org,
            ])
            ->filter(fn ($c) => $c['name'] !== '')
            ->values()->all();
    }

    /**
     * A Purchase vendor's team: its contact people and its registered workers.
     *
     * purchase_contacts has no vendor column of its own — the link is the pivot
     * the Purchase module owns — so the contact side is read through whatever
     * join that module provides and skipped if it is not there, rather than
     * inventing one.
     */
    private function purchaseVendorPeople(int $tenantId, int $vendorId): array
    {
        $org = DB::table('purchase_vendors')->where('tenant_id', $tenantId)
            ->where('id', $vendorId)->value('company_name');

        $people = $this->purchaseContacts($tenantId, $vendorId, $org);

        $workers = DB::table('purchase_workers')
            ->where('tenant_id', $tenantId)
            ->where('purchase_vendor_id', $vendorId)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'designation', 'email'])
            ->map(fn ($w) => [
                'ref'          => 'purchase_worker:'.$w->id,
                'user_id'      => null,
                'name'         => $w->full_name,
                'designation'  => $w->designation,
                'email'        => $w->email,
                'organisation' => $org,
            ])
            ->filter(fn ($w) => (string) $w['name'] !== '')
            ->values()->all();

        return [...$people, ...$workers];
    }

    private function purchaseContacts(int $tenantId, int $vendorId, ?string $org): array
    {
        $table = DB::getSchemaBuilder();
        if (! $table->hasTable('purchase_contacts')) {
            return [];
        }

        $q = DB::table('purchase_contacts')->where('tenant_id', $tenantId);

        // The column the module actually uses to attach a contact to a vendor.
        $link = collect(['purchase_vendor_id', 'vendor_id'])
            ->first(fn ($c) => $table->hasColumn('purchase_contacts', $c));

        if (! $link) {
            return [];
        }

        if ($table->hasColumn('purchase_contacts', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return $q->where($link, $vendorId)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'designation', 'email'])
            ->map(fn ($c) => [
                'ref'          => 'purchase_contact:'.$c->id,
                'user_id'      => null,
                'name'         => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
                'designation'  => $c->designation,
                'email'        => $c->email,
                'organisation' => $org,
            ])
            ->filter(fn ($c) => $c['name'] !== '')
            ->values()->all();
    }

    /** A third-party vendor's team: its contacts and its workforce. */
    private function tpvVendorPeople(int $tenantId, int $vendorId): array
    {
        $org = DB::table('vendors')->where('tenant_id', $tenantId)
            ->where('id', $vendorId)->value('company_name');

        /*
         * `tpv_contacts`, NOT `vendor_contacts`.
         *
         * The TPV Contacts tab writes `tpv_contacts` (TpvContactController →
         * TpvContactService → TpvContact). `vendor_contacts` is a legacy table
         * whose only writer is an optional inline `contacts[]` array on vendor
         * create/update that no TPV screen sends — so it is empty, and this
         * column offered nobody however many contacts a vendor had. Purchase
         * never drifted: it reads `purchase_contacts`, the table its own form
         * writes, which is why that column worked and this one did not.
         *
         * `party_ref` is an opaque de-duplication string, never a foreign key
         * (see KickoffAttendee), and the picker copies name/designation/email
         * onto the row — so changing the source table changes no stored id.
         */
        $contacts = DB::table('tpv_contacts')
            ->where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'designation', 'email'])
            ->map(fn ($c) => [
                'ref'          => 'tpv_contact:'.$c->id,
                'user_id'      => null,
                'name'         => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
                'designation'  => $c->designation,
                'email'        => $c->email,
                'organisation' => $org,
            ])
            ->filter(fn ($c) => (string) $c['name'] !== '')
            ->values()->all();

        $workers = DB::table('tpv_workers')
            ->where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->orderBy('name')
            ->get(['id', 'name', 'designation'])
            ->map(fn ($w) => [
                'ref'          => 'tpv_worker:'.$w->id,
                'user_id'      => null,
                'name'         => $w->name,
                'designation'  => $w->designation,
                'email'        => null,
                'organisation' => $org,
            ])
            ->filter(fn ($w) => (string) $w['name'] !== '')
            ->values()->all();

        return [...$contacts, ...$workers];
    }

    private function humanise(?string $role): string
    {
        return $role ? ucwords(str_replace('_', ' ', $role)) : '';
    }
}
