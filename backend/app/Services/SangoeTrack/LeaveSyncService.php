<?php

namespace App\Services\SangoeTrack;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeaveType;
use App\Services\Hr\LeaveApplicationService;
use App\Services\Hr\LeaveApprovalService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls SangoeTrack leave into hr_leave_applications.
 *
 * Separate from AttendanceSyncService because the write paths share nothing: a
 * leave is one row spanning a range with an approval lifecycle and a balance
 * ledger behind it, where attendance is one row per day with no lifecycle.
 *
 * The split of responsibility is the same though — this class fetches, resolves
 * the leave type, and maps the remote status; every rule (day count, balance
 * deduction, audit trail) stays inside the existing Leave services.
 */
class LeaveSyncService
{
    /** Remote status -> CRM status. Anything unmapped is treated as Submitted. */
    private const STATUS_MAP = [
        'approved'  => HrLeaveApplication::APPROVED,
        'approve'   => HrLeaveApplication::APPROVED,
        'accepted'  => HrLeaveApplication::APPROVED,
        'rejected'  => HrLeaveApplication::REJECTED,
        'reject'    => HrLeaveApplication::REJECTED,
        'declined'  => HrLeaveApplication::REJECTED,
        'cancelled' => HrLeaveApplication::CANCELLED,
        'canceled'  => HrLeaveApplication::CANCELLED,
        'pending'   => HrLeaveApplication::SUBMITTED,
        'submitted' => HrLeaveApplication::SUBMITTED,
        'applied'   => HrLeaveApplication::SUBMITTED,
    ];

    /** tenant_id => [lowercased type name => HrLeaveType id] */
    private array $typeCache = [];

    public function __construct(
        private SangoeTrackClient $client,
        private LeaveApplicationService $applications,
        private LeaveApprovalService $approvals,
    ) {
    }

    /**
     * Sync one employee's leave for one month.
     *
     * @return array{employee_id:int, name:string, synced:int, skipped:int, failed:int, errors:array<int,string>}
     *
     * @throws SangoeTrackException on a remote/transport failure
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

        $rows = $this->client->getLeaves(
            (int) $employee->sangoetrack_user_id,
            $workspaceId,
            $month,
            $year,
        );

        foreach ($rows as $row) {
            try {
                $handled = $this->syncOne($employee, $row, $workspaceId);
                $handled ? $result['synced']++ : $result['skipped']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = $e->getMessage();
                Log::channel('hr')->warning('SangoeTrack leave sync failed', [
                    'employee_id' => $employee->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

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
                        $summary['failed']++;
                        $summary['details'][] = [
                            'employee_id' => $employee->id, 'name' => $employee->name,
                            'synced' => 0, 'skipped' => 0, 'failed' => 1,
                            'errors' => [$e->getMessage()],
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

    /* ─────────────────────────── internals ─────────────────────────── */

    /** @return bool true when the leave was written/considered, false when unusable */
    private function syncOne(HrEmployee $employee, array $row, int $workspaceId): bool
    {
        $externalId = $this->pick($row, ['id', 'leave_id', 'application_id']);
        $from       = $this->pick($row, ['from_date', 'start_date', 'from', 'leave_from']);
        $to         = $this->pick($row, ['to_date', 'end_date', 'to', 'leave_to']) ?: $from;

        if (! $externalId || ! $from) {
            return false;   // nothing to key on, or no range — cannot be represented
        }

        $typeId = $this->resolveLeaveType($employee, $row, $workspaceId);

        if (! $typeId) {
            return false;
        }

        $sync = $this->applications->syncExternal($employee, [
            'sangoetrack_leave_id' => (int) $externalId,
            'leave_type_id'        => $typeId,
            'from_date'            => Carbon::parse((string) $from)->toDateString(),
            'to_date'              => Carbon::parse((string) $to)->toDateString(),
            'half_day'             => (bool) $this->pick($row, ['half_day', 'is_half_day', 'halfday']),
            'reason'               => $this->pick($row, ['reason', 'remarks', 'note', 'description']),
        ]);

        $this->applyStatus($sync['application'], $row, (int) $employee->tenant_id);

        return true;
    }

    /**
     * Move the application to the remote's decided state.
     *
     * Approval goes through LeaveApprovalService so the balance ledger entry is
     * written by the service that owns balances — setting status directly would
     * silently leave balances overstated. Guarded on the current status, so a
     * nightly re-sync of an already-approved leave deducts nothing a second time.
     */
    private function applyStatus(HrLeaveApplication $app, array $row, int $tenantId): void
    {
        $raw = $this->pick($row, ['status', 'leave_status', 'state']);
        $target = self::STATUS_MAP[strtolower(trim((string) $raw))] ?? null;

        if ($target === null || $target === $app->status) {
            return;
        }

        // Only a pending application can be decided; anything else is already final.
        if (! in_array($app->status, [HrLeaveApplication::DRAFT, HrLeaveApplication::SUBMITTED], true)) {
            return;
        }

        $remarks = 'Synced from SangoeTrack';

        if ($target === HrLeaveApplication::APPROVED) {
            // recordUsage() needs an active balance; without one the deduction
            // cannot be represented, so leave it pending rather than approving
            // a leave whose balance impact would silently vanish.
            if (! $app->employee_leave_balance_id) {
                Log::channel('hr')->warning('SangoeTrack leave approved remotely but no CRM balance exists', [
                    'application_id' => $app->id, 'employee_id' => $app->employee_id,
                ]);

                return;
            }

            $this->approvals->approve($app->id, $remarks, $tenantId, null);

            return;
        }

        if ($target === HrLeaveApplication::REJECTED) {
            $this->approvals->reject($app->id, $remarks, $tenantId, null);
        }

        // Cancelled arrives as a plain status change; there is no balance to undo
        // because a cancelled leave was never approved through this CRM.
        if ($target === HrLeaveApplication::CANCELLED) {
            $app->update(['status' => HrLeaveApplication::CANCELLED, 'decision_remarks' => $remarks]);
            $app->recordAudit('Leave Cancelled (SangoeTrack)', null, $remarks, [], 'SangoeTrack');
        }
    }

    /**
     * Map the remote leave type onto an HrLeaveType by name, per tenant.
     * Unmatched types are reported rather than invented — creating leave types
     * from a sync would let a remote typo become a permanent CRM master record.
     */
    private function resolveLeaveType(HrEmployee $employee, array $row, int $workspaceId): ?int
    {
        $tenantId = (int) $employee->tenant_id;

        if (! isset($this->typeCache[$tenantId])) {
            $this->typeCache[$tenantId] = HrLeaveType::where('tenant_id', $tenantId)
                ->get()
                ->mapWithKeys(fn ($t) => [strtolower(trim((string) $t->name)) => (int) $t->id])
                ->all();
        }

        $name = $this->pick($row, ['leave_type', 'type', 'leave_type_name', 'leaveType.name']);

        if (! $name) {
            return null;
        }

        $key = strtolower(trim((string) $name));

        if (isset($this->typeCache[$tenantId][$key])) {
            return $this->typeCache[$tenantId][$key];
        }

        Log::channel('hr')->warning('SangoeTrack leave type has no CRM equivalent', [
            'tenant_id' => $tenantId, 'remote_type' => $name,
        ]);

        return null;
    }

    private function pick(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($row, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
