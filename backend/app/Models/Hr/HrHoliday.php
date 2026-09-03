<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Holiday (Leave Phase 5). Tenant-scoped; reuses Org Setup dept/designation for scope. */
class HrHoliday extends Model
{
    use Auditable;

    protected $table = 'hr_holidays';

    public const TYPES = ['National', 'Festival', 'Company', 'Optional'];
    public const SCOPES = ['Organization', 'Department', 'Designation'];

    protected $fillable = [
        'tenant_id', 'title', 'description', 'holiday_date', 'holiday_type',
        'applicable_for', 'department_id', 'designation_id', 'is_optional', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_optional'  => 'boolean',
        'is_active'    => 'boolean',
    ];

    /**
     * Only the holidays this employee actually observes.
     *
     * A holiday can be scoped to the whole organisation, to one department, or to
     * one designation — the admin screen offers all three. Neither app endpoint
     * applied the scope, so a holiday created for a single department was sent to
     * every employee in the workspace, and the phone showed people a day off they
     * do not get.
     *
     * A scoped holiday with no target is treated as organisation-wide rather than
     * hidden: the row exists because somebody meant to publish it.
     */
    public function scopeVisibleTo($query, ?HrEmployee $employee)
    {
        // Restrict ONLY on the two values that actually narrow the audience.
        // Anything else — 'Organization', null, or a legacy value like 'All' —
        // is workspace-wide. Whitelisting 'Organization' instead would fail
        // closed, and silently hiding a real holiday is far worse than showing
        // one: nobody notices a day off that never appeared.
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

    /**
     * The CSS class the app receives, carrying the TYPE and not just a yes/no.
     *
     * It was derived from is_optional alone, so National, Festival and Company
     * holidays were indistinguishable on the phone even though the admin screen
     * makes you choose between them.
     */
    public function appClassName(): string
    {
        // Exactly 'optional' — not a tidier 'optional-holiday'. The calendar
        // tests `h.className == 'optional'` by string equality in six places to
        // pick the badge, the dot colour and the "Optional Leave" label, so any
        // other spelling silently renders an optional day as a mandatory one.
        if ($this->is_optional || $this->holiday_type === 'Optional') {
            return 'optional';
        }

        // Everything else reads as mandatory to the app (`!= 'optional'`), and
        // the type is carried rather than blanked so the screen can start
        // distinguishing them without another backend change.
        return match ($this->holiday_type) {
            'National' => 'national-holiday',
            'Festival' => 'festival-holiday',
            'Company'  => 'company-holiday',
            default    => 'public-holiday',
        };
    }

    /** The calendar dot colour, following the same type rule as the class name. */
    public function appColor(): string
    {
        if ($this->is_optional || $this->holiday_type === 'Optional') {
            return '#f59e0b';
        }

        return match ($this->holiday_type) {
            'National' => '#7C3AED',
            'Festival' => '#ec4899',
            'Company'  => '#0ea5e9',
            default    => '#7C3AED',
        };
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
