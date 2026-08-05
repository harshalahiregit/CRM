<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Services\SangoeTrack\AttendanceSyncService;
use App\Services\SangoeTrack\SangoeTrackException;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * On-demand SangoeTrack attendance pull, for a "Sync now" button on the
 * Attendance screen. The nightly command is the primary path; this exists so an
 * admin does not have to wait for it after fixing something in SangoeTrack.
 *
 * Always tenant-scoped to the caller — a sync can never reach another tenant's
 * employees, even though the SangoeTrack credentials are process-wide.
 */
class SangoeTrackSyncController extends Controller
{
    public function __construct(private AttendanceSyncService $sync)
    {
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to sync attendance');

        if (! config('sangoetrack.enabled')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'SangoeTrack integration is disabled.',
            ], 422);
        }

        $data = $request->validate([
            'month'       => 'nullable|integer|min:1|max:12',
            'year'        => 'nullable|integer|min:2000|max:2100',
            'employee_id' => 'nullable|integer',
        ]);

        $today = Carbon::today();
        $month = (string) ($data['month'] ?? $today->month);
        $year  = (string) ($data['year'] ?? $today->year);

        $tenantId = $request->user()->tenant_id;

        try {
            if (! empty($data['employee_id'])) {
                // Tenant filter in the lookup, not after it — otherwise the 404
                // would leak whether another tenant's employee id exists.
                $employee = HrEmployee::where('tenant_id', $tenantId)
                    ->findOrFail($data['employee_id']);

                $result  = $this->sync->syncEmployee($employee, $month, $year);
                $summary = [
                    'employees' => 1,
                    'synced'    => $result['synced'],
                    'skipped'   => $result['skipped'],
                    'failed'    => $result['failed'],
                    'details'   => [$result],
                ];
            } else {
                $summary = $this->sync->syncAll($tenantId, $month, $year);
            }
        } catch (SangoeTrackException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 502);   // upstream failure, not the caller's fault
        }

        return response()->json([
            'status'  => 'success',
            'period'  => $month.'/'.$year,
            'summary' => $summary,
            'message' => sprintf(
                'Synced %d day(s) for %d employee(s); %d skipped, %d failed.',
                $summary['synced'], $summary['employees'], $summary['skipped'], $summary['failed']
            ),
        ]);
    }
}
