<?php

namespace App\Models\Compliance;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Compliance\ChecklistSubject;
use App\Support\Compliance\ComplianceStatus as Status;
use App\Support\Compliance\RiskBand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One issued instance of a template, attached to whatever it is about.
 */
class ComplianceChecklist extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'compliance_checklists';

    protected $fillable = [
        'tenant_id','compliance_template_id','checklistable_type','checklistable_id',
        'reference','title','status','public_token',
        'issued_by','assigned_to','assignee_name','assignee_email','due_date',
        'responses','score','max_score','risk_percent','risk_band','critical_failures',
        'submitted_at','selfie_path','latitude','longitude','submitted_ip','submitted_user_agent',
        'closed_at',
    ];

    protected $casts = [
        'responses'         => 'array',
        'critical_failures' => 'array',
        'due_date'          => 'date',
        'submitted_at'      => 'datetime',
        'closed_at'         => 'datetime',
        'score'             => 'integer',
        'max_score'         => 'integer',
        'risk_percent'      => 'decimal:2',
        'latitude'          => 'decimal:7',
        'longitude'         => 'decimal:7',
    ];

    /**
     * The fill-in link is a bearer credential — anyone holding it can answer.
     * It must never ride along in a list/show payload; the one place it is
     * legitimately revealed is the audited issue/reopen response.
     */
    protected $hidden = ['public_token'];

    protected $appends = ['status_label', 'risk_band_label', 'is_overdue', 'subject'];

    public function template()
    {
        return $this->belongsTo(ComplianceTemplate::class, 'compliance_template_id');
    }

    /** Vendor today; project/worker/staff-exit next. */
    public function checklistable()
    {
        return $this->morphTo();
    }

    public function signatures()
    {
        return $this->hasMany(ComplianceSignature::class, 'compliance_checklist_id')->orderBy('signed_at');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function getRiskBandLabelAttribute(): ?string
    {
        return $this->risk_band ? RiskBand::label($this->risk_band) : null;
    }

    /**
     * The subject as structured data — {type, id, name, label} — so a listing can
     * show "Apex Structural Pvt Ltd" rather than the raw row id, which means
     * nothing to the person reading it.
     *
     * Callers listing many rows must eager-load `checklistable`, or this appends
     * an N+1. ComplianceChecklistService::list does.
     */
    public function getSubjectAttribute(): ?array
    {
        if (! $this->checklistable_type) {
            return null;
        }

        $key = ChecklistSubject::keyFor($this->checklistable_type);

        return [
            'type'  => $key,
            'id'    => $this->checklistable_id,
            'name'  => ChecklistSubject::nameOf($this->checklistable),
            'label' => ChecklistSubject::label($key),
        ];
    }

    /** Only an unsubmitted checklist can be late — a closed one is just history. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date
            && ! Status::isClosed($this->status)
            && $this->submitted_at === null
            && $this->due_date->isPast();
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', Status::CLOSED);
    }

    public function scopeAwaitingSignature($query)
    {
        return $query->whereIn('status', [Status::SUBMITTED, Status::MANAGER_APPROVED]);
    }

    public function scopeHighRisk($query)
    {
        return $query->where('risk_band', RiskBand::HIGH);
    }

    public function scopeFor($query, string $type, int $id)
    {
        return $query->where('checklistable_type', $type)->where('checklistable_id', $id);
    }
}
