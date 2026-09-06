<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\AttendanceReportService;
use Illuminate\Http\Request;

/**
 * Attendance reports for payroll: per employee, per month, per department.
 *
 * Read-only. Nothing here changes a figure, so a payroll run cannot be affected
 * by somebody looking at it — which is what makes it safe to give to everyone
 * who needs to see the numbers.
 */
class AttendanceReportController extends Controller
{
    public function __construct(private AttendanceReportService $reports)
    {
    }

    public function monthly(Request $request)
    {
        $data = $this->validated($request, [
            'department'  => 'nullable|string|max:120',
            'employee_id' => 'nullable|integer',
        ]);

        return response()->json([
            'status' => 'success',
            'data'   => $this->reports->monthly(
                (int) $request->user()->tenant_id,
                $data['month'],
                $data['department'] ?? null,
                isset($data['employee_id']) ? (int) $data['employee_id'] : null
            ),
        ]);
    }

    public function byDepartment(Request $request)
    {
        $data = $this->validated($request);

        return response()->json([
            'status' => 'success',
            'data'   => $this->reports->byDepartment((int) $request->user()->tenant_id, $data['month']),
        ]);
    }

    public function forEmployee(Request $request, int $employeeId)
    {
        $data = $this->validated($request);

        return response()->json([
            'status' => 'success',
            'data'   => $this->reports->forEmployee(
                (int) $request->user()->tenant_id, $employeeId, $data['month']
            ),
        ]);
    }

    /**
     * Month defaults to this one.
     *
     * The regex is the validation: a month is YYYY-MM and anything else is a
     * mistake worth naming, rather than something to quietly reinterpret.
     */
    private function validated(Request $request, array $extra = []): array
    {
        $data = $request->validate(array_merge([
            'month' => 'nullable|regex:/^\d{4}-\d{2}$/',
        ], $extra));

        $data['month'] = $data['month'] ?? now()->format('Y-m');

        return $data;
    }
}
