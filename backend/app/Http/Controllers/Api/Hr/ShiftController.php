<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrShift;
use App\Models\Hr\HrShiftTiming;
use App\Services\Hr\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shift Management. Thin: validate, delegate, return JSON.
 *
 * Reads are open to HR users; writes require HR-queue management, because a shift
 * change alters what someone is expected to work and when they are marked absent.
 */
class ShiftController extends Controller
{
    public function __construct(private ShiftService $service)
    {
    }

    /* ── Master ───────────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->shifts($this->tenant($request), $request->only(['is_active', 'shift_type'])),
        ]);
    }

    public function meta()
    {
        return response()->json([
            'shift_types' => HrShift::TYPES,
            'days'        => HrShiftTiming::DAYS,
        ]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->showShift($id, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->assertCanManage($request);

        return response()->json(
            $this->service->createShift($this->shiftRules($request), $this->tenant($request), $request->user()), 201
        );
    }

    public function update(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json(
            $this->service->updateShift($id, $this->shiftRules($request, partial: true), $this->tenant($request), $request->user())
        );
    }

    public function destroy(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->service->deleteShift($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Rotations ────────────────────────────────────────────────────── */

    public function rotations(Request $request)
    {
        return response()->json(['data' => $this->service->rotations($this->tenant($request))]);
    }

    public function saveRotation(Request $request, ?int $id = null)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'                  => ($id ? 'sometimes|' : '').'required|string|max:150',
            'code'                  => 'nullable|string|max:40',
            'description'           => 'nullable|string|max:1000',
            'is_active'             => 'nullable|boolean',
            'steps'                 => 'nullable|array',
            'steps.*.shift_id'      => 'required|integer',
            'steps.*.duration_days' => 'nullable|integer|min:1|max:365',
        ]);

        return response()->json(
            $this->service->saveRotation($id, $data, $this->tenant($request), $request->user()),
            $id ? 200 : 201
        );
    }

    public function destroyRotation(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->service->deleteRotation($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Assignment + history ─────────────────────────────────────────── */

    public function roster(Request $request)
    {
        return response()->json(['data' => $this->service->roster($this->tenant($request), $request->only(['shift_id']))]);
    }

    public function assign(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'employee_id'    => 'required|integer',
            'shift_id'       => 'nullable|integer',
            'rotation_id'    => 'nullable|integer',
            'effective_from' => 'required|date',
            'reason'         => 'nullable|string|max:500',
        ]);

        return response()->json($this->service->assign($data, $this->tenant($request), $request->user()), 201);
    }

    public function history(Request $request, int $employeeId)
    {
        return response()->json(['data' => $this->service->history($employeeId, $this->tenant($request))]);
    }

    /** Which shift applies on a given date, and whether it is a weekly off. */
    public function forDate(Request $request, int $employeeId)
    {
        $date = $request->query('date', now()->toDateString());
        $result = $this->service->shiftForDate($employeeId, $this->tenant($request), $date);

        return response()->json([
            'date'  => $date,
            'shift' => $result['shift'] ? [
                'id'             => $result['shift']->id,
                'name'           => $result['shift']->name,
                'shift_type'     => $result['shift']->shift_type,
                'is_night_shift' => (bool) $result['shift']->is_night_shift,
            ] : null,
            'start_time'  => $result['timing']?->start_time,
            'end_time'    => $result['timing']?->end_time,
            'is_week_off' => $result['is_week_off'],
            'reason'      => $result['reason'],
        ]);
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    private function shiftRules(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name'                     => "{$req}|string|max:150",
            'code'                     => 'nullable|string|max:40',
            'shift_type'               => ['nullable', Rule::in(HrShift::TYPES)],
            'is_night_shift'           => 'nullable|boolean',
            'grace_in_minutes'         => 'nullable|integer|min:0|max:600',
            'grace_out_minutes'        => 'nullable|integer|min:0|max:600',
            'break_minutes'            => 'nullable|integer|min:0|max:600',
            'full_day_hours'           => 'nullable|numeric|min:0|max:24',
            'half_day_hours'           => 'nullable|numeric|min:0|max:24',
            'description'              => 'nullable|string|max:1000',
            'is_active'                => 'nullable|boolean',
            'timings'                  => 'nullable|array|max:7',
            'timings.*.day_of_week'    => 'required|integer|min:0|max:6',
            'timings.*.start_time'     => 'nullable|string|max:5',
            'timings.*.end_time'       => 'nullable|string|max:5',
            'timings.*.is_week_off'    => 'nullable|boolean',
            'timings.*.week_numbers'   => 'nullable|array',
            'timings.*.week_numbers.*' => 'integer|min:1|max:5',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage shifts');
    }
}
