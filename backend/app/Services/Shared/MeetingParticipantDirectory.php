<?php

namespace App\Services\Shared;

use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Shared\MeetingVisibility;
use App\Support\Vendor\VendorStatus;

/**
 * Everyone who can be put on a meeting, grouped by category.
 *
 * The old picker was a flat list hard-coded to whereIn('role', ['admin','staff']),
 * so a manager, an HR executive and a doctor were all invisible in it -- they
 * could not be invited to a meeting at all, however much somebody needed them
 * there. Customers and vendors were served by two other endpoints the form
 * called separately, which is why they never appeared side by side.
 *
 * One endpoint, one shape, categories in one list. Adding a category is a row in
 * CATEGORIES plus a loader; nothing else in the engine changes.
 *
 * ── Selectable is not the same as allowed in ────────────────────────────
 * Customers and third-party vendors appear here and can be invited, e-mailed and
 * marked present. That does NOT let them into the Meetings module: the routes
 * are closed to their roles (MeetingVisibility::INTERNAL_ROLES) and they keep
 * the read-only governance view in their own portal. Being invited to a meeting
 * and being able to schedule one are different things, and this class is only
 * ever asked the first question.
 */
class MeetingParticipantDirectory
{
    /**
     * The categories offered, in the order the picker shows them.
     *
     * `side` lands on the attendee row and drives the internal/external split
     * the MOM and the attendance register already read.
     */
    /** Which engine's vendors the `vendor` category should list. */
    public const SHARED = 'shared';

    public const PURCHASE = 'purchase';

    public const CATEGORIES = [
        ['key' => 'admin',    'label' => 'Admin',            'side' => 'internal'],
        ['key' => 'staff',    'label' => 'Staff',            'side' => 'internal'],
        ['key' => 'manager',  'label' => 'Manager',          'side' => 'internal'],
        ['key' => 'hr',       'label' => 'HR',               'side' => 'internal'],
        ['key' => 'doctor',   'label' => 'Doctor',           'side' => 'internal'],
        ['key' => 'customer', 'label' => 'Customer',         'side' => 'external'],
        ['key' => 'vendor',   'label' => 'Vendor (TPV)',     'side' => 'external'],
    ];

    public function __construct(private CustomerServiceContract $customers) {}

    /**
     * Every category with its people. Empty categories are kept, not dropped.
     *
     * $engine only changes WHICH vendors the vendor category lists -- Purchase
     * meetings belong to purchase_vendors, a different company set with unrelated
     * ids. Everything else is identical, which is why this is one class: the
     * Purchase picker had exactly the same ['admin','staff'] hard-code and the
     * same three uninvitable roles, and fixing it in one place is the only way
     * the two do not drift again.
     */
    public function all(int $tenantId, string $engine = self::SHARED): array
    {
        $byRole = $this->internalUsers($tenantId);

        return array_map(function (array $c) use ($tenantId, $byRole, $engine) {
            $people = match ($c['key']) {
                'customer' => $this->customerPeople($tenantId),
                'vendor'   => $engine === self::PURCHASE
                    ? $this->purchaseVendorPeople($tenantId)
                    : $this->vendorPeople($tenantId),
                default    => $byRole[$c['key']] ?? [],
            };

            if ($c['key'] === 'vendor' && $engine === self::PURCHASE) {
                $c['label'] = 'Vendor (Purchase)';
            }

            return $c + ['people' => $people];
        }, self::CATEGORIES);
    }

    /** Active Purchase vendors -- a separate table from the TPV vendors above. */
    private function purchaseVendorPeople(int $tenantId): array
    {
        return \App\Models\Purchase\PurchaseVendor::forTenant($tenantId)
            ->where('status', 'Active')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'email'])
            ->map(fn ($v) => [
                'id'           => 'purchase_vendor:'.$v->id,
                // A PurchaseVendor authenticates as itself, not through a User,
                // so there is no user_id to bell -- the invitation is the e-mail.
                'user_id'      => null,
                'name'         => $v->company_name,
                'email'        => $v->email,
                'organisation' => $v->company_name,
                'designation'  => null,
                'category'     => 'vendor',
                'side'         => 'external',
            ])->values()->all();
    }

    /**
     * Internal users, fetched once and grouped, rather than a query per role.
     *
     * Only active logins: a deactivated account on a participant list is somebody
     * who will never receive the invitation, and the send would report success.
     */
    private function internalUsers(int $tenantId): array
    {
        return User::where('tenant_id', $tenantId)
            ->whereIn('role', MeetingVisibility::INTERNAL_ROLES)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'designation', 'role'])
            ->groupBy('role')
            ->map(fn ($rows, $role) => $rows->map(fn ($u) => [
                'id'          => 'user:'.$u->id,
                'user_id'     => $u->id,
                'name'        => $u->name,
                'email'       => $u->email,
                'designation' => $u->designation,
                'category'    => $role,
                'side'        => 'internal',
            ])->values()->all())
            ->all();
    }

    /** Customers, through the Customer module's contract -- never its tables. */
    private function customerPeople(int $tenantId): array
    {
        return array_values(array_map(fn (array $c) => [
            'id'           => 'customer:'.$c['id'],
            'user_id'      => null,
            'name'         => $c['name'] ?? $c['company'] ?? null,
            'email'        => $c['email'] ?? null,
            'organisation' => $c['company'] ?? null,
            'designation'  => null,
            'category'     => 'customer',
            'side'         => 'external',
        ], array_filter($this->customers->listCustomers($tenantId))));
    }

    /** Active third-party vendors. */
    private function vendorPeople(int $tenantId): array
    {
        return Vendor::forTenant($tenantId)
            ->where('status', VendorStatus::ACTIVE)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'email', 'user_id'])
            ->map(fn ($v) => [
                'id'           => 'vendor:'.$v->id,
                // The vendor's portal login, so the bell reaches them too.
                'user_id'      => $v->user_id,
                'name'         => $v->company_name,
                'email'        => $v->email,
                'organisation' => $v->company_name,
                'designation'  => null,
                'category'     => 'vendor',
                'side'         => 'external',
            ])->values()->all();
    }
}
