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

    /**
     * Break a link that points somewhere it should not.
     *
     * The remedy for the two blocking structural faults: a user_id naming an
     * account that no longer exists, and one naming an account in another
     * workspace. Both leave the employee unreachable and, in the second case,
     * are a tenant boundary violation sitting in the data.
     *
     * Clears the link ONLY. It does not delete the account, because on the
     * cross-tenant path that account belongs to somebody else entirely, and it
     * does not touch the employee record beyond the one column. The employee then
     * shows up as "employed, no login", which is a state this panel already knows
     * how to resolve.
     */
    public function unlink(Request $request, int $employeeId)
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to change the directory');

        $tenantId = $this->tenant($request);
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);

        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $was = $employee->user_id;
        $employee->update(['user_id' => null]);

        \Illuminate\Support\Facades\Log::channel('hr')->warning('Employee login link cleared', [
            'employee_id' => $employee->id, 'was_user_id' => $was, 'by' => $request->user()->id,
        ]);

        return response()->json(['data' => ['employee_id' => $employee->id, 'cleared_user_id' => $was]]);
    }

    /**
     * Push the employee's identity onto its login, on demand.
     *
     * The same one-way sync EmployeeService::update performs on every save, run
     * without needing a save. It is what resolves an identity_mismatch: the
     * employee record owns these fields, so the fix is always to make the account
     * agree with it, never the other way round.
     *
     * Offered rather than applied automatically. A mismatch can mean the employee
     * record is the stale one — somebody changed a login email directly years ago
     * — and only a person can tell which side is true.
     */
    public function resync(Request $request, int $employeeId)
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to change the directory');

        $tenantId = $this->tenant($request);
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);

        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $user = app(EmployeeIdentityService::class)->syncLoginFromEmployee($employee, $request->user());

        if (! $user) {
            throw new BusinessException('That employee has no login in this workspace to update.', 422);
        }

        return response()->json(['data' => ['employee_id' => $employee->id, 'user_id' => $user->id]]);
    }

    /**
     * Mark a reconciliation issue as looked at and intentional.
     *
     * Some of what this panel reports is correct in a particular workspace: a
     * director whose login was made through a portal path years ago and works
     * perfectly well, an employee who genuinely has no login. A panel that keeps
     * shouting about those is one people stop reading, which costs more than the
     * issue it was reporting.
     *
     * Dismissing hides the issue, it does not change anything about the records.
     * The issue reappears the moment the underlying facts change, because the key
     * is derived from the problem rather than stored with it.
     */
    public function dismiss(Request $request)
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to change the directory');

        $data = $request->validate(['key' => 'required|string|max:191']);

        return response()->json(['data' => [
            'dismissed' => $this->service->dismiss($this->tenant($request), $data['key'], $request->user()->id),
        ]]);
    }

    /** Undo a dismissal — the issue comes back into the panel. */
    public function restore(Request $request)
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to change the directory');

        $data = $request->validate(['key' => 'required|string|max:191']);

        return response()->json(['data' => [
            'dismissed' => $this->service->restore($this->tenant($request), $data['key']),
        ]]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }
}
