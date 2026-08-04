<?php

namespace App\Console\Commands\SangoeTrack;

use App\Models\Hr\HrEmployee;
use App\Services\SangoeTrack\LeaveSyncService;
use App\Services\SangoeTrack\SangoeTrackException;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pulls SangoeTrack leave into hr_leave_applications for every mapped employee.
 *
 * Kept separate from sangoetrack:sync-attendance so one failing side cannot take
 * the other down, and so either can be re-run on its own after a fix.
 */
class SyncSangoeTrackLeaves extends Command
{
    protected $signature = 'sangoetrack:sync-leaves
                            {--tenant= : Restrict to one tenant id}
                            {--employee= : Restrict to one CRM employee id}
                            {--month= : 1-12 (default: current month)}
                            {--year= : e.g. 2026 (default: current year)}
                            {--previous : Sync the previous month instead}';

    protected $description = 'Sync leave applications from SangoeTrack into the CRM';

    public function handle(LeaveSyncService $sync): int
    {
        if (! config('sangoetrack.enabled')) {
            $this->warn('SangoeTrack is disabled. Set SANGOETRACK_ENABLED=true to run this sync.');

            return self::SUCCESS;
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

        $this->info("Syncing SangoeTrack leave for {$month}/{$year}");

        try {
            if ($employeeId = $this->option('employee')) {
                $employee = HrEmployee::find((int) $employeeId);

                if (! $employee) {
                    $this->error("Employee #{$employeeId} not found.");

                    return self::FAILURE;
                }

                $one = $sync->syncEmployee($employee, $month, $year);
                $summary = [
                    'employees' => 1, 'synced' => $one['synced'],
                    'skipped' => $one['skipped'], 'failed' => $one['failed'],
                    'details' => [$one],
                ];
            } else {
                $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
                $summary  = $sync->syncAll($tenantId, $month, $year);
            }
        } catch (SangoeTrackException $e) {
            $this->error($e->getMessage());
            Log::channel('hr')->error('SangoeTrack leave sync aborted', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        foreach ($summary['details'] as $row) {
            foreach ($row['errors'] as $error) {
                $this->warn(sprintf('  %s: %s', $row['name'] ?? ('#'.$row['employee_id']), $error));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'employees %d | leaves synced %d | skipped %d | failed %d',
            $summary['employees'], $summary['synced'], $summary['skipped'], $summary['failed']
        ));

        Log::channel('hr')->info('SangoeTrack leave sync complete', [
            'period' => $month.'/'.$year,
            'employees' => $summary['employees'],
            'synced' => $summary['synced'],
            'skipped' => $summary['skipped'],
            'failed' => $summary['failed'],
        ]);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
