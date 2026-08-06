<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\EmployeeLifecycleService;
use App\Services\Hr\LoanRecoveryService;
use Illuminate\Http\Request;

/**
 * #37 — the employee's work across Projects, Tasks, Tickets and the Knowledge
 * Base, and #38 — their loan recovery status. Both read-only.
 *
 * No HR-queue gate on the lifecycle read: it aggregates records the caller can
 * already reach in their own modules, and each module keeps its own permissions
 * when the user follows the jump link. Loan recovery IS gated — it exposes debt.
 */
class EmployeeLifecycleController extends Controller
{
    public function __construct(
        private EmployeeLifecycleService $lifecycle,
        private LoanRecoveryService $recovery,
    ) {
    }

    /**
     * #37, plus the #38 loan summary.
     *
     * One call for the profile rather than two: the loan block is gated on HR
     * permission because it exposes debt, so a caller without it gets the
     * lifecycle with `loans` omitted rather than a 403 for the whole page.
     */
    public function show(Request $request, int $employeeId)
    {
        $tenantId = $this->tenant($request);
        $payload = $this->lifecycle->forEmployee($employeeId, $tenantId);

        if ($request->user()->canManageHrQueue()) {
            $payload['loans'] = $this->recovery->forEmployee($employeeId, $tenantId);
        }

        return response()->json($payload);
    }

    /* ── #38 loan recovery ────────────────────────────────────────────── */

    /**
     * One employee's loan position on its own.
     *
     * Separate from show() so the profile's Bank & Tax card does not have to pull
     * the whole lifecycle — four module queries — to render a single summary.
     */
    public function employeeLoans(Request $request, int $employeeId)
    {
        $this->can($request);

        return response()->json($this->recovery->forEmployee($employeeId, $this->tenant($request)));
    }

    public function loanRecovery(Request $request, int $loanId)
    {
        $this->can($request);

        return response()->json($this->recovery->forLoan($loanId, $this->tenant($request)));
    }

    public function outstandingLoans(Request $request)
    {
        $this->can($request);

        return response()->json([
            'data' => $this->recovery->outstanding($this->tenant($request),
                $request->only(['employee_id', 'department', 'period'])),
        ]);
    }

    public function runRecovery(Request $request, int $runId)
    {
        $this->can($request);

        return response()->json($this->recovery->forRun($runId, $this->tenant($request)));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to view loan recovery');
    }
}
