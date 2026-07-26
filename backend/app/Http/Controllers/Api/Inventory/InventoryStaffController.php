<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Internal people in the current tenant — the source for the "Staff" filter on
 * Inventory reports and the history ledger (who recorded the movement).
 *
 * Inventory owns this rather than borrowing Tasks'/Helpdesk's equivalent, so no
 * module depends on another module's routes. Read-only and tenant-scoped.
 */
class InventoryStaffController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function index(Request $request)
    {
        $this->denyExternal($request);

        $staff = User::where('tenant_id', $request->user()->tenant_id)
            ->where('status', 'active')
            ->whereNotIn('role', ['client', 'vendor', 'third_party_vendor'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return $this->success($staff, 'Staff retrieved');
    }
}
