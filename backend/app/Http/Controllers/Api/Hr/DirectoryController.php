<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Services\Hr\DirectoryReconciliationService;
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

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }
}
