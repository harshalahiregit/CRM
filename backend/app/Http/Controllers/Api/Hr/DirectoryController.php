<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Services\Hr\DirectoryReconciliationService;
use App\Services\Hr\EmployeeIdentityService;
use Illuminate\Http\Request;

/**
 * Where the staff and employee directories disagree.
 *
 * Read-only apart from `link`, which points an employment record at a login
 * that already exists. It never creates either side: creating an employee from
 * a user means inventing a joining date, and an invented joining date is a
 * wrong figure in every service and gratuity calculation from that day on.
 */
class DirectoryController extends Controller
{
    public function __construct(private DirectoryReconciliationService $service)
    {
    }

    public function reconciliation(Request $request)
    {
        return response()->json(['data' => $this->service->report($this->tenant($request))]);
    }

    public function link(Request $request, int $employeeId)
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to change the directory');

        $data = $request->validate(['user_id' => 'required|integer']);

        $tenantId = $this->tenant($request);

        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        $user = User::where('tenant_id', $tenantId)->find($data['user_id']);

        if (! $employee || ! $user) {
            throw new BusinessException('Employee or login not found', 404);
        }

        return response()->json(['data' => $this->service->link($employee, $user, $tenantId)]);
    }

    /**
     * Give an employee who has none a login of their own.
     *
     * The other half of the panel's job. Reporting that eleven people cannot
     * sign in, and offering nothing to do about it, is how a diagnostic screen
     * gets ignored — the fix was a route away the whole time and no button ever
     * called it.
     *
     * Every refusal that matters lives in EmployeeIdentityService::provision and
     * is reused rather than restated here: it will not cross tenants, will not
     * repurpose a client/vendor/TPV portal address as a staff login, will not
     * make a second account for somebody who already has one, and will not steal
     * a login another employee is already using. It links to a matching account
     * in preference to creating one.
     *
     * The temporary password is returned ONCE, to the admin who pressed the
     * button, and is never logged or stored — the admin passes it on and the
     * person changes it.
     */
    public function provision(Request $request, int $employeeId)
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to change the directory');

        $tenantId = $this->tenant($request);

        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);

        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $result = app(EmployeeIdentityService::class)->provision($employee, 'staff', $request->user());

        return response()->json(['data' => [
            'employee_id'        => $employee->id,
            'user_id'            => $result['user']->id,
            'email'              => $result['user']->email,
            'created'            => $result['created'],
            'temporary_password' => $result['temporary_password'],
        ]]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }
}
