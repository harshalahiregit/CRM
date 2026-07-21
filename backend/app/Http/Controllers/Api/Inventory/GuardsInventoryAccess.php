<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\Inventory\Product;
use App\Models\Inventory\Voucher;
use App\Models\Inventory\Warehouse;
use Illuminate\Http\Request;

/**
 * Inventory access rules.
 *
 * Same shape as the barrier in Projects/Tasks/Helpdesk: admin does everything;
 * everyone else gets an operational slice, and edit/delete is gated on
 * ownership. Inventory is closest to Helpdesk — stock is shared operational
 * data, so VISIBILITY stays open to internal staff (you cannot run a warehouse
 * if you can't see what's on the shelf). The barrier is on CHANGE:
 *
 *   • Master data (warehouses, settings, custom fields, imports, reset,
 *     deleting items) → admin only.
 *   • An item → edited by admin or whoever created it (the Projects rule:
 *     the creator owns what they created).
 *   • A voucher → edit/post/cancel/delete by admin, its creator, or the manager
 *     of the warehouse it belongs to (the equivalent of Helpdesk's department
 *     managers).
 *   • Portal roles (client/vendor/tpv) → no access at all.
 */
trait GuardsInventoryAccess
{
    private const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor'];

    /** Portal users (clients/vendors) have no business in the stock room. */
    protected function denyExternal(Request $request): void
    {
        abort_if(
            in_array($request->user()?->role, self::EXTERNAL_ROLES, true),
            403,
            'You do not have access to Inventory.'
        );
    }

    /** Master-data changes an ordinary storekeeper shouldn't make. */
    protected function requireAdmin(Request $request, string $what = 'do that'): void
    {
        $this->denyExternal($request);
        abort_unless($request->user()?->role === 'admin', 403, "Only an admin can {$what}.");
    }

    protected function isAdmin(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }

    /** Warehouses this user manages — their equivalent of a department manager. */
    protected function managedWarehouseIds(Request $request): array
    {
        return Warehouse::forTenant($request->user()->tenant_id)
            ->where('manager_id', $request->user()->id)
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** May this user change this item? Admin, or the person who added it. */
    protected function canManageProduct(Request $request, int $productId): bool
    {
        if ($this->isAdmin($request)) {
            return true;
        }

        $createdBy = Product::forTenant($request->user()->tenant_id)->whereKey($productId)->value('created_by');

        return $createdBy !== null && (int) $createdBy === (int) $request->user()->id;
    }

    protected function guardProductManage(Request $request, int $productId, string $what = 'edit this item'): void
    {
        $this->denyExternal($request);
        abort_unless(
            $this->canManageProduct($request, $productId),
            403,
            "Only an admin or the person who added it can {$what}."
        );
    }

    /**
     * May this user act on this voucher? Admin, its creator, or the manager of
     * the warehouse it moves stock at.
     */
    protected function canManageVoucher(Request $request, int $voucherId): bool
    {
        if ($this->isAdmin($request)) {
            return true;
        }

        $v = Voucher::forTenant($request->user()->tenant_id)
            ->whereKey($voucherId)->first(['created_by', 'warehouse_id']);

        if (! $v) {
            return false;
        }

        if ((int) $v->created_by === (int) $request->user()->id) {
            return true;
        }

        return $v->warehouse_id && in_array((int) $v->warehouse_id, $this->managedWarehouseIds($request), true);
    }

    protected function guardVoucherManage(Request $request, int $voucherId, string $what = 'change this document'): void
    {
        $this->denyExternal($request);
        abort_unless(
            $this->canManageVoucher($request, $voucherId),
            403,
            "Only an admin, the document's creator, or the warehouse manager can {$what}."
        );
    }
}
