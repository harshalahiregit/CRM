<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEvent;
use App\Services\Hr\EventService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Leave → Company Events. Thin: validate, delegate, return JSON.
 * Reads open to HR users; writes require HR-queue management. Tenant-scoped, audited.
 */
class EventController extends Controller
{
    public function __construct(private EventService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list(
            $this->tenant($request),
            $request->only(['year', 'status', 'search', 'department_id'])
        ));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);

        return response()->json(
            $this->service->create($this->validated($request), $this->tenant($request), $request->user()),
            201
        );
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);

        return response()->json(
            $this->service->update($id, $this->validated($request, true), $this->tenant($request), $request->user())
        );
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json(
            $this->service->setStatus($id, (bool) $data['is_active'], $this->tenant($request), $request->user())
        );
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'title'          => "$req|string|max:150",
            'description'    => 'nullable|string',
            'start_date'     => "$req|date",
            'end_date'       => 'nullable|date',
            // Free-form hex so a workspace is not stuck with the six offered in
            // the form, but validated: the app puts this straight into a colour.
            'color'          => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'applicable_for' => ['nullable', Rule::in(HrEvent::SCOPES)],
            'department_id'  => 'nullable|integer',
            'designation_id' => 'nullable|integer',
            'is_active'      => 'boolean',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage events');
    }
}
