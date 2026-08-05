<?php

namespace App\Console\Commands\SangoeTrack;

use App\Models\Hr\HrEmployee;
use App\Services\SangoeTrack\AttendanceSyncService;
use App\Services\SangoeTrack\SangoeTrackException;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pulls SangoeTrack attendance into hr_attendance for every mapped employee.
 *
 * Defaults to the current month, which is what the nightly schedule wants: the
 * whole month is re-fetched each run so late punches and back-dated corrections
 * made in SangoeTrack land here too. syncExternal() is a no-op for unchanged
 * days, so re-syncing 30 days nightly costs reads, not writes or audit rows.
 */
class SyncSangoeTrackAttendance extends Command
{
    protected $signature = 'sangoetrack:sync-attendance
                            {--tenant= : Restrict to one tenant id}
                            {--employee= : Restrict to one CRM employee id}
                            {--month= : 1-12 (default: current month)}
                            {--year= : e.g. 2026 (default: current year)}
                            {--previous : Sync the previous month instead (month-end catch-up)}';

    protected $description = 'Sync attendance from SangoeTrack into the CRM hr_attendance table';

    public function handle(AttendanceSyncService $sync): int
    {
        if (! config('sangoetrack.enabled')) {
            $this->warn('SangoeTrack is disabled. Set SANGOETRACK_ENABLED=true to run this sync.');

            return self::SUCCESS;   // not a failure — nothing is configured yet
        }

        $anchor = Carbon::today();
        if ($this->option('previous')) {
            $anchor = $anchor->subMonthNoOverflow();
        }

        $month = (string) ((int) ($this->option('month') ?: $anchor->month));
        $year  = (string) ((int) ($this->option('year') ?: $anchor->year));

        if ((int) $month < 1 || (int) $month > 12) {
            $this->error('--month must be 1-12.');

            return self::FAILURE;
        }

        $this->info("Syncing SangoeTrack attendance for {$month}/{$year}");

        try {
            if ($employeeId = $this->option('employee')) {
                $employee = HrEmployee::find((int) $employeeId);

                if (! $employee) {
                    $this->error("Employee #{$employeeId} not found.");

                    return self::FAILURE;
                }

                $one = $sync->syncEmployee($employee, $month, $year);
                $summary = [
                    'employees' => 1,
                    'synced'    => $one['synced'],
                    'skipped'   => $one['skipped'],
                    'failed'    => $one['failed'],
                    'details'   => [$one],
                ];
            } else {
                $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
                $summary  = $sync->syncAll($tenantId, $month, $year);
            }
        } catch (SangoeTrackException $e) {
            // Transport/config failure — the remote is unreachable or misconfigured.
            $this->error($e->getMessage());
            Log::channel('hr')->error('SangoeTrack sync aborted', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        foreach ($summary['details'] as $row) {
            foreach ($row['errors'] as $error) {
                $this->warn(sprintf('  %s: %s', $row['name'] ?? ('#'.$row['employee_id']), $error));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'employees %d | days synced %d | skipped %d | failed %d',
            $summary['employees'], $summary['synced'], $summary['skipped'], $summary['failed']
        ));

        Log::channel('hr')->info('SangoeTrack attendance sync complete', [
            'period'    => $month.'/'.$year,
            'employees' => $summary['employees'],
            'synced'    => $summary['synced'],
            'skipped'   => $summary['skipped'],
            'failed'    => $summary['failed'],
        ]);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
