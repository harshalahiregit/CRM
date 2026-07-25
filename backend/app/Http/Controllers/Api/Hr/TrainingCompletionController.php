<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\TrainingCompletionService;
use Illuminate\Http\Request;

/**
 * L&D → Training Completion (Phase 6). Read-only derived view. Tenant-scoped.
 */
class TrainingCompletionController extends Controller
{
    public function __construct(private TrainingCompletionService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_id', 'training_program_id', 'department'])));
    }

    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->forEmployee($employee, $this->tenant($request)));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }
}
