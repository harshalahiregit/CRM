<?php

namespace App\Http\Controllers\Api\Medical;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Support\Medical\DoctorOptions;
use Illuminate\Http\Request;

/**
 * The internal-doctor list behind every "pick a doctor" control.
 *
 * ── Why this is not the admin directory endpoint ────────────────────────
 * GET /medical/doctors already lists doctors, but it is `role:admin` and it
 * returns the whole profile — e-mail, phone, clinic address, signature and
 * stamp paths, the login's status. A vendor filling in a worker's medical needs
 * exactly one thing: which doctors can I name, and what is their licence. So
 * this is a separate, narrower door rather than a widened one: reusing the
 * admin endpoint would have meant handing a supplier the staff directory to
 * save writing twenty lines.
 *
 * Mounted three times, once per identity, each with its own guard:
 *   /api/medical/doctor-options                  any signed-in User (admin, staff)
 *   /api/portal/medical/doctor-options           a TPV login
 *   /api/portal/purchase/medical/doctor-options  a PurchaseVendor
 *
 * The module is fixed by the route, not read from the request, so a Purchase
 * vendor cannot ask for the TPV list by changing a parameter.
 */
class DoctorOptionsController extends Controller
{
    /** Admin/staff side: the module comes from ?module=, which staff may choose. */
    public function index(Request $request)
    {
        $module = $request->query('module');
        if (! in_array($module, DoctorOptions::MODULES, true)) {
            $module = null;   // everything this tenant has
        }

        return response()->json([
            'data' => DoctorOptions::forTenant((int) $request->user()->tenant_id, $module),
        ]);
    }

    /** TPV portal: the module is TPV, whatever the caller says. */
    public function tpv(Request $request)
    {
        return response()->json([
            'data' => DoctorOptions::forTenant((int) $request->user()->tenant_id, 'tpv'),
        ]);
    }

    /** Purchase portal: the caller is a PurchaseVendor, not a User. */
    public function purchase(Request $request)
    {
        $vendor = $request->user();
        abort_unless($vendor instanceof PurchaseVendor, 403, 'This area is for Purchase vendor accounts only.');

        return response()->json([
            'data' => DoctorOptions::forTenant((int) $vendor->tenant_id, 'purchase'),
        ]);
    }
}
