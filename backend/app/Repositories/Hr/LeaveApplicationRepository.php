<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrLeaveApplication;
use Illuminate\Database\Eloquent\Collection;

/** Read queries for leave applications / approvals (Phase 3 + 4). Tenant-scoped. */
class LeaveApplicationRepository
{
    /** Applications list with the common filters (used by both apply + approval views). */
    /**
     * @param  \App\Models\User|null  $actor  Whose view this is. Null is
     *         unscoped, so existing callers are unaffected.
     */
    public function filtered(int $tenantId, array $f, ?\App\Models\User $actor = null): Collection
    {
        // One call, a different column: leave rows carry employee_id, the
        // directory carries id. That is the whole adoption surface — the
        // hierarchy walk lives in the resolver, not here.
        //
        // BRANCH omitted for the same reason as the employee directory: the
        // column exists, the data does not.
        $scoped = app(\App\Services\Auth\ScopeResolver::class)->applyToQuery(
            HrLeaveApplication::where('tenant_id', $tenantId),
            $actor,
            'employee_id',
            [\App\Support\Hr\DataScope::OWN, \App\Support\Hr\DataScope::DEPARTMENT, \App\Support\Hr\DataScope::TEAM],
        );

        return $scoped
            // user_id for the same reason as find(): anything that notifies from
            // one of these rows needs employee->user to resolve.
            ->with(['employee:id,tenant_id,user_id,name,employee_code,department,designation', 'leaveType:id,name,code,color', 'policy:id,name'])
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['leave_type_id']), fn ($q) => $q->where('leave_type_id', $f['leave_type_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('from_date', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('to_date', '<=', $f['to']))
            ->orderByDesc('id')->get();
    }

    public function find(int $id, int $tenantId): ?HrLeaveApplication
    {
        return HrLeaveApplication::where('tenant_id', $tenantId)
            // tenant_id and user_id are not decoration. A column list silently
            // gives you NULL for anything it omits, so both were missing here
            // and neither failed loudly:
            //   - user_id backs HrEmployee::user(), a belongsTo. Without it the
            //     relation resolves to NULL.
            //   - tenant_id is what RequestNotifier dispatches under. A null
            //     tenant matches no rules, so the engine created nothing.
            // LeaveApprovalService approves from THIS model, so approving a
            // leave deducted the balance and wrote the audit line while telling
            // the employee nothing on any channel — no exception, no log line,
            // nothing to notice. Adding a column to a list like this is cheap;
            // leaving one out is invisible.
            ->with(['employee:id,tenant_id,user_id,name,employee_code,department,designation', 'leaveType:id,name,code,color', 'policy:id,name,negative_balance_allowed', 'auditLogs'])
            ->find($id);
    }

    public function forEmployee(int $employeeId, int $tenantId): Collection
    {
        return HrLeaveApplication::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->with(['leaveType:id,name,code,color'])
            ->orderByDesc('id')->get();
    }

    /** Queue counters by status. */
    public function statusCounts(int $tenantId): array
    {
        $rows = HrLeaveApplication::where('tenant_id', $tenantId)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        return [
            'pending'   => (int) ($rows[HrLeaveApplication::SUBMITTED] ?? 0),
            'approved'  => (int) ($rows[HrLeaveApplication::APPROVED] ?? 0),
            'rejected'  => (int) ($rows[HrLeaveApplication::REJECTED] ?? 0),
            'cancelled' => (int) ($rows[HrLeaveApplication::CANCELLED] ?? 0),
        ];
    }
}
