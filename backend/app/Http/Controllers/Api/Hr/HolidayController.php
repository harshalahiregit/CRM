<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrHoliday;
use App\Services\Hr\HolidayService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Leave → Holiday Calendar (Phase 5). Thin: validate, delegate, return JSON.
 * Reads open to HR users; writes require HR-queue management. Tenant-scoped, audited.
 */
class HolidayController extends Controller
{
    public function __construct(private HolidayService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['year', 'holiday_type', 'department_id', 'status', 'search', 'employee_id', 'from'])));
    }

    public function calendar(Request $request)
    {
        return response()->json($this->service->calendar($this->tenant($request), $request->only(['year', 'holiday_type', 'department_id', 'status', 'employee_id'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);

        return response()->json($this->service->create($this->validated($request), $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->update($id, $this->validated($request, true), $this->tenant($request), $request->user()));
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json($this->service->setStatus($id, (bool) $data['is_active'], $this->tenant($request), $request->user()));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'title'          => "$req|string|max:150",
            'description'    => 'nullable|string',
            'holiday_date'   => "$req|date",
            'holiday_type'   => [$partial ? 'sometimes' : 'required', Rule::in(HrHoliday::TYPES)],
            'applicable_for' => ['nullable', Rule::in(HrHoliday::SCOPES)],
            'department_id'  => 'nullable|integer',
            'designation_id' => 'nullable|integer',
            'is_optional'    => 'boolean',
            'is_active'      => 'boolean',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage holidays');
    }
}
