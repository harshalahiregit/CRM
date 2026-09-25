<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrHoliday;
use Illuminate\Database\Eloquent\Collection;
use App\Models\User;
use App\Repositories\Hr\Concerns\ScopesEmployeeData;

/** Read queries for the Holiday Calendar (Leave Phase 5). Tenant-scoped; no writes. */
class HolidayRepository
{
    use ScopesEmployeeData;

    /**
     * The company holiday calendar. DELIBERATELY NOT employee-scoped.
     *
     * hr_holidays has no employee_id: a public holiday is the same fact for
     * everybody, and narrowing it by the reader's department would hide real
     * company holidays rather than protect anybody. So list(), find(),
     * existsForScope() and stats() stay tenant-wide.
     *
     * The ONE employee-shaped thing here is the optional employee_id FILTER,
     * which answers "which holidays apply to this person" by reading their
     * department and designation. That lookup is guarded: left open, a
     * department-scoped actor could pass somebody else's id and infer their
     * department and designation from which holidays came back. Guarding the
     * lookup is not the same as scoping the calendar.
     */
    public function list(int $tenantId, array $f, ?User $actor = null): Collection
    {
        if (! empty($f['employee_id'])) {
            $this->assertEmployeeInScope($actor, $f['employee_id']);
        }

        $query = HrHoliday::where('tenant_id', $tenantId)
            ->with(['department:id,name', 'designation:id,name'])
            ->when(! empty($f['year']) && $f['year'] !== 'All', fn ($q) => $q->whereYear('holiday_date', $f['year']))
            ->when(! empty($f['holiday_type']) && $f['holiday_type'] !== 'All', fn ($q) => $q->where('holiday_type', $f['holiday_type']))
            ->when(! empty($f['department_id']) && $f['department_id'] !== 'All', fn ($q) => $q->where('department_id', $f['department_id']))
            ->when(isset($f['status']) && $f['status'] !== '' && $f['status'] !== 'All', fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['search']), fn ($q) => $q->where('title', 'like', '%'.$f['search'].'%'))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('holiday_date', '>=', $f['from']));

        // Holidays applicable to a specific employee (Organization + their dept + their designation).
        if (! empty($f['employee_id'])) {
            $employee = HrEmployee::where('tenant_id', $tenantId)->find($f['employee_id']);
            $query->where(function ($q) use ($employee) {
                $q->where('applicable_for', 'Organization');
                if ($employee?->department_id) {
                    $q->orWhere(fn ($w) => $w->where('applicable_for', 'Department')->where('department_id', $employee->department_id));
                }
                if ($employee?->designation_id) {
                    $q->orWhere(fn ($w) => $w->where('applicable_for', 'Designation')->where('designation_id', $employee->designation_id));
                }
            });
        }

        return $query->orderBy('holiday_date')->get();
    }

    public function find(int $id, int $tenantId): ?HrHoliday
    {
        return HrHoliday::where('tenant_id', $tenantId)
            ->with(['department:id,name', 'designation:id,name'])
            ->find($id);
    }

    /** Duplicate check: same date + scope (applicable_for + department + designation). */
    public function existsForScope(int $tenantId, string $date, string $applicableFor, ?int $departmentId, ?int $designationId, ?int $ignoreId = null): bool
    {
        return HrHoliday::where('tenant_id', $tenantId)
            ->whereDate('holiday_date', $date)
            ->where('applicable_for', $applicableFor)
            ->where(fn ($q) => $departmentId ? $q->where('department_id', $departmentId) : $q->whereNull('department_id'))
            ->where(fn ($q) => $designationId ? $q->where('designation_id', $designationId) : $q->whereNull('designation_id'))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function stats(int $tenantId, ?int $year): array
    {
        $base = HrHoliday::where('tenant_id', $tenantId)
            ->when($year, fn ($q) => $q->whereYear('holiday_date', $year));

        return [
            'total'    => (clone $base)->count(),
            'national' => (clone $base)->where('holiday_type', 'National')->count(),
            'festival' => (clone $base)->where('holiday_type', 'Festival')->count(),
            'optional' => (clone $base)->where('holiday_type', 'Optional')->count(),
            'active'   => (clone $base)->where('is_active', true)->count(),
        ];
    }
}
