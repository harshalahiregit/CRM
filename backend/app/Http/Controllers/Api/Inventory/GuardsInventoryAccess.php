<?php

namespace App\Http\Controllers\Api\Inventory;

use Illuminate\Http\Request;

/**
 * Inventory access rules (Phase 1).
 *
 * Stock is shared operational data — every internal person needs to see what's
 * on the shelf and record what they moved, so there's no per-user visibility
 * barrier here (unlike Projects/Tasks). The line is drawn at MASTER DATA:
 * warehouses and deletions are admin-only, and portal roles are kept out
 * entirely.
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
}
