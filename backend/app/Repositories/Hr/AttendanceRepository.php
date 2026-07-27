<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrAttendance;
use App\Repositories\BaseRepository;

class AttendanceRepository extends BaseRepository
{
    protected string $modelClass = HrAttendance::class;

    /**
     * Tenant-scoped attendance list. Employee-level filters (department,
     * designation, search) are applied through the employee relationship so no
     * employee data is duplicated onto the attendance row.
     */
    public function filtered(int $tenantId, array $filters)
    {
        $query = HrAttendance::where('tenant_id', $tenantId)->with('employee');

        if (! empty($filters['date'])) {
            $query->whereDate('date', $filters['date']);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['shift']) && $filters['shift'] !== 'All') {
            $query->where('shift', $filters['shift']);
        }

        $dept   = $filters['department'] ?? null;
        $desig  = $filters['designation'] ?? null;
        $search = $filters['search'] ?? null;
        if (($dept && $dept !== 'All') || ($desig && $desig !== 'All') || $search) {
            $query->whereHas('employee', function ($q) use ($dept, $desig, $search) {
                if ($dept && $dept !== 'All') {
                    $q->where('department', $dept);
                }
                if ($desig && $desig !== 'All') {
                    $q->where('designation', $desig);
                }
                if ($search) {
                    $q->where(function ($s) use ($search) {
                        $s->where('name', 'like', '%'.$search.'%')
                          ->orWhere('employee_code', 'like', '%'.$search.'%')
                          ->orWhere('department', 'like', '%'.$search.'%')
                          ->orWhere('designation', 'like', '%'.$search.'%');
                    });
                }
            });
        }

        return $query->orderByDesc('date')->orderByDesc('id')->get();
    }
}
