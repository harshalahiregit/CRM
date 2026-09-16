<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A company event — something happening, as opposed to a day off.
 *
 * The attendance app keeps events and holidays in two separate lists and draws
 * them differently, so they are two tables here as well. Scoping is deliberately
 * the same rule as HrHoliday: an event for one department should not be
 * announced to the whole workspace.
 */
class HrEvent extends Model
{
    use Auditable;

    protected $table = 'hr_events';

    public const SCOPES = ['Organization', 'Department', 'Designation'];

    /** Offered in the admin form; any hex the API accepts still works. */
    public const COLORS = ['#7C3AED', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899', '#ef4444'];

    protected $fillable = [
        'tenant_id', 'title', 'description', 'start_date', 'end_date', 'color',
        'applicable_for', 'department_id', 'designation_id', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_active'  => 'boolean',
    ];

    /**
     * Only the events this employee is actually part of.
     *
     * Restricts ONLY on the two values that narrow the audience. Anything else —
     * 'Organization', null, or a legacy value — is workspace-wide, because
     * silently hiding an announcement is worse than showing a spare one. Same
     * reasoning, and same shape, as HrHoliday::scopeVisibleTo.
     */
    public function scopeVisibleTo($query, ?HrEmployee $employee)
    {
        if (! $employee) {
            return $query->whereNotIn('applicable_for', ['Department', 'Designation']);
        }

        return $query->where(function ($q) use ($employee) {
            $q->whereNotIn('applicable_for', ['Department', 'Designation'])
              ->orWhereNull('applicable_for')
              ->orWhere(function ($d) use ($employee) {
                  $d->where('applicable_for', 'Department')
                    ->where(fn ($x) => $x->whereNull('department_id')
                        ->orWhere('department_id', $employee->department_id));
              })
              ->orWhere(function ($g) use ($employee) {
                  $g->where('applicable_for', 'Designation')
                    ->where(fn ($x) => $x->whereNull('designation_id')
                        ->orWhere('designation_id', $employee->designation_id));
              });
        });
    }

    /** A one-day event may leave end_date empty; the app still needs both. */
    public function effectiveEnd(): \Illuminate\Support\Carbon
    {
        return $this->end_date ?? $this->start_date;
    }

    public function department()
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function designation()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }
}
