<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\TrainingCompletionService;
use Illuminate\Http\Request;

/**
 * L&D → Training Completion (Phase 6). Read-only derived view. Tenant-scoped.
 *
 * Gated and scoped in Phase 7. Being inside the tenant was the only thing asked
 * of a caller here: every one of the twelve sibling Learning controllers —
 * programs, sessions, assignments, attendance, assessments, certificates,
 * quizzes, categories, types, providers and the reports — gates on
 * canManageHrQueue(), and this one alone did not. Any authenticated account,
 * including a portal login, could read who had completed, failed or been
 * certified on every training in the company, and name any employee id in the
 * URL to single one out.
 *
 * This is an ADMINISTRATIVE view, not self-service. Its two consumers are the
 * L&D module and the HR Employee Profile's training tab, both of which read
 * OTHER people's records; there is no "my trainings" screen behind it, so
 * gating it takes nothing away from an employee.
 *
 * Permission and scope stay separate, as everywhere else in the module:
 * canManageHrQueue() decides whether you may open the completion view at all,
 * and the actor passed to the service decides whose records appear on it.
 */
class TrainingCompletionController extends Controller
{
    public function __construct(private TrainingCompletionService $service)
    {
    }

    public function index(Request $request)
    {
        $this->gate($request);

        return response()->json($this->service->list(
            $this->tenant($request),
            $request->only(['employee_id', 'training_program_id', 'department']),
            $request->user(),
        ));
    }

    public function forEmployee(Request $request, int $employee)
    {
        $this->gate($request);

        // An id in the URL is the first thing anyone tries, so the direct-id
        // surface gets the Phase 4 treatment: 404, not 403, because telling
        // somebody "you may not see employee 41" confirms that employee 41
        // exists and sits outside their scope.
        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($request->user(), $employee);

        return response()->json($this->service->forEmployee($employee, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    /** Same authority and same shape as every sibling Learning controller. */
    private function gate(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to view training completion');
    }
}
