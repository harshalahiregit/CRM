<?php

namespace App\Services\SangoeTrack;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Services\Hr\AttendanceService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls SangoeTrack attendance into the CRM's own hr_attendance rows.
 *
 * Deliberately thin: it fetches, normalises, and hands each day to
 * AttendanceService::syncExternal(). Status derivation, working hours and
 * overtime are NOT computed here — doing so would fork the rules that the
 * manual and check-in paths already own.
 *
 * Failure isolation is per employee and per day. One ineligible employee or one
 * unparseable row must never abort a nightly run across the whole company, so
 * those are counted and reported rather than thrown.
 */
class AttendanceSyncService
{
    public function __construct(
        private SangoeTrackClient $client,
        private AttendanceService $attendance,
    ) {
    }

    /**
     * Sync one employee for one month.
     *
     * @param  string  $month  "1".."12"
     * @param  string  $year   "2026"
     * @return array{employee_id:int, name:string, synced:int, skipped:int, failed:int, errors:array<int,string>}
     *
     * @throws SangoeTrackException on a remote/transport failure (caller decides)
     */
    public function syncEmployee(HrEmployee $employee, string $month, string $year): array
    {
        $result = [
            'employee_id' => $employee->id,
            'name'        => $employee->name,
            'synced'      => 0,
            'skipped'     => 0,
            'failed'      => 0,
            'errors'      => [],
        ];

        if (! $employee->sangoetrack_user_id) {
            $result['skipped']++;
            $result['errors'][] = 'Not mapped to a SangoeTrack user.';

            return $result;
        }

        $workspaceId = (int) ($employee->sangoetrack_workspace_id ?: config('sangoetrack.workspace_id'));

        if ($workspaceId <= 0) {
            $result['skipped']++;
            $result['errors'][] = 'No workspace id (set SANGOETRACK_WORKSPACE_ID or the employee override).';

            return $result;
        }

        // A transport failure is the caller's to handle — it means the run should
        // stop, not that this employee has no attendance.
        $rows = $this->client->getAttendanceHistory(
            (int) $employee->sangoetrack_user_id,
            $workspaceId,
            $month,
            $year,
        );

        foreach ($rows as $row) {
            $day = $this->normaliseRow($row, (int) $year, (int) $month);

            if ($day === null) {
                $result['skipped']++;
                continue;
            }

            try {
                $this->attendance->syncExternal($employee, $day);
                $result['synced']++;
            } catch (BusinessException $e) {
                // Ineligible day (employee inactive, or before joining date).
                // Expected and benign — ensureRecord() guards this deliberately.
                $result['skipped']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = $day['date'].': '.$e->getMessage();
                Log::channel('hr')->warning('SangoeTrack day sync failed', [
                    'employee_id' => $employee->id,
                    'date'        => $day['date'],
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        $employee->forceFill(['sangoetrack_synced_at' => now()])->saveQuietly();

        return $result;
    }

    /**
     * Sync every mapped employee, optionally restricted to one tenant.
     *
     * @return array{employees:int, synced:int, skipped:int, failed:int, details:array<int,array>}
     */
    public function syncAll(?int $tenantId, string $month, string $year): array
    {
        $summary = ['employees' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];

        HrEmployee::query()
            ->whereNotNull('sangoetrack_user_id')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('id')
            ->chunkById(100, function ($employees) use (&$summary, $month, $year) {
                foreach ($employees as $employee) {
                    $summary['employees']++;

                    try {
                        $one = $this->syncEmployee($employee, $month, $year);
                    } catch (SangoeTrackException $e) {
                        // Remote problem on this employee's call — record and move on
                        // rather than losing the employees already synced this run.
                        $summary['failed']++;
                        $summary['details'][] = [
                            'employee_id' => $employee->id,
                            'name'        => $employee->name,
                            'synced'      => 0, 'skipped' => 0, 'failed' => 1,
                            'errors'      => [$e->getMessage()],
                        ];
                        continue;
                    }

                    $summary['synced']  += $one['synced'];
                    $summary['skipped'] += $one['skipped'];
                    $summary['failed']  += $one['failed'];
                    $summary['details'][] = $one;
                }
            });

        return $summary;
    }

    /**
     * Map one remote row onto the shape syncExternal() expects, or null if the
     * row carries no usable date.
     *
     * Times may arrive as "09:15", "09:15:00" or a full datetime; a bare time is
     * anchored to the row's own date so it can never land on today by accident.
     *
     * @return array{date:string, check_in:?string, check_out:?string, status:?string}|null
     */
    private function normaliseRow(array $row, int $year, int $month): ?array
    {
        $rawDate = $this->pick($row, 'date');
        $date    = $this->parseDate($rawDate, $year, $month);

        if ($date === null) {
            return null;
        }

        return [
            'date'      => $date,
            'check_in'  => $this->parseTime($this->pick($row, 'check_in'), $date),
            'check_out' => $this->parseTime($this->pick($row, 'check_out'), $date),
            'status'    => $this->mapStatus($this->pick($row, 'status')),
        ];
    }

    private function parseDate(mixed $value, int $year, int $month): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // A bare day number ("7") is meaningful only with the requested period.
        if (is_int($value) || (is_string($value) && ctype_digit($value) && (int) $value <= 31)) {
            $day = (int) $value;

            return checkdate($month, $day, $year)
                ? Carbon::create($year, $month, $day)->toDateString()
                : null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function parseTime(mixed $value, string $date): ?string
    {
        if ($value === null || $value === '' || $value === '00:00:00' || $value === '-') {
            return null;
        }

        $value = trim((string) $value);

        try {
            // Bare time -> anchor to the row's date. Anything else is a full
            // timestamp already and is normalised as-is.
            if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
                return Carbon::parse($date.' '.$value)->toDateTimeString();
            }

            return Carbon::parse($value)->toDateTimeString();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Remote status string -> a CRM status, or null to let the CRM derive it. */
    private function mapStatus(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $key = strtolower(trim($value));

        return config('sangoetrack.status_map.'.$key);
    }

    /** First present value among the configured candidate keys for $field. */
    private function pick(array $row, string $field): mixed
    {
        foreach ((array) config('sangoetrack.map.'.$field, []) as $key) {
            $value = Arr::get($row, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
