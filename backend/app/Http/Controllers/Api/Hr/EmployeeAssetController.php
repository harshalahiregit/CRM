<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Services\Inventory\AssetService;
use Illuminate\Http\Request;

/**
 * An employee's assets — read straight out of the Inventory register.
 *
 * HRMS keeps no asset table and no asset rules: every figure below comes from
 * AssetService, the same service the Inventory module itself uses. This class
 * checks tenancy and permission, then delegates. Nothing else belongs here.
 */
class EmployeeAssetController extends Controller
{
    public function __construct(private AssetService $assets)
    {
    }

    /* GET /api/hr/employees/{employee}/assets */
    public function index(Request $request, HrEmployee $employee)
    {
        $this->authorizeEmployee($request, $employee);

        return response()->json(
            $this->assets->forEmployee($employee->id, (int) $employee->tenant_id)
        );
    }

    /* GET /api/hr/employees/{employee}/assets/summary */
    public function summary(Request $request, HrEmployee $employee)
    {
        $this->authorizeEmployee($request, $employee);

        return response()->json(
            $this->assets->summaryForEmployee($employee->id, (int) $employee->tenant_id)
        );
    }

    /* GET /api/hr/employees/{employee}/assets/{asset} — read-only detail + history */
    public function show(Request $request, HrEmployee $employee, int $asset)
    {
        $this->authorizeEmployee($request, $employee);

        return response()->json($this->assets->show($asset, (int) $employee->tenant_id));
    }

    /** 404 for another tenant's record — the codebase hides existence. */
    private function authorizeEmployee(Request $request, HrEmployee $employee): void
    {
        abort_unless((int) $employee->tenant_id === (int) $request->user()->tenant_id, 404);
    }
}
