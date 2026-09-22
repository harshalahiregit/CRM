<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One POSH complaint.
 *
 * Who may read it is hr_posh_case_members and nothing else — see
 * PoshAccessResolver. This model deliberately exposes no helper that answers
 * "may this person see it", because a second answer to that question is how a
 * bypass gets added without anybody noticing.
 *
 * The respondent and the complainant are DATA here, not members. Neither has a
 * surface in this phase: what a respondent may see is an unanswered legal
 * question, and the complainant's view is a separate restricted surface built
 * later.
 *
 * Soft-deleted rather than destroyed, and anonymised rather than purged, so a
 * removal can never take the audit trail with it. No retention period is
 * defaulted and no purge exists — the structure is here, the policy is not.
 */
class HrPoshCase extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'hr_posh_cases';

    public const COMPLAINANT_EMPLOYEE = 'employee';
    public const COMPLAINANT_TOKEN = 'token';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_UNDER_INQUIRY = 'under_inquiry';
    public const STATUS_INQUIRY_COMPLETE = 'inquiry_complete';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'tenant_id', 'reference', 'committee_id',
        'complainant_type', 'complainant_employee_id', 'complainant_label',
        'respondent_employee_id', 'respondent_label',
        'incident_at', 'incident_place', 'narrative',
        'status', 'outcome', 'findings_published_at',
        // The procedural clock. Dates, never deadlines: the statute's timings
        // vary by how a workspace counts them, so the product records what
        // happened and when, and leaves the arithmetic to the people who are
        // accountable for it.
        'complaint_received_at', 'acknowledged_at',
        'inquiry_started_at', 'inquiry_completed_at',
        'retention_policy_id', 'anonymised_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'incident_at'           => 'datetime',
        'findings_published_at' => 'datetime',
        'complaint_received_at' => 'datetime',
        'acknowledged_at'       => 'datetime',
        'inquiry_started_at'    => 'datetime',
        'inquiry_completed_at'  => 'datetime',
        'anonymised_at'         => 'datetime',
    ];

    public function members()
    {
        return $this->hasMany(HrPoshCaseMember::class, 'case_id')->orderBy('id');
    }

    /** The people who may read this case right now. */
    public function activeMembers()
    {
        return $this->members()->whereNull('removed_at');
    }

    public function reads()
    {
        return $this->hasMany(HrPoshCaseRead::class, 'case_id');
    }

    /**
     * Evidence and documents.
     *
     * Storage is the shared attachments table; AUTHORISATION is not shared.
     * AttachmentService performs no checks of its own, so every POSH
     * attachment route resolves the case through PoshAccessResolver first and
     * looks the file up within that case — never by bare id.
     */
    public function attachments()
    {
        return $this->morphMany(\App\Models\Shared\Attachment::class, 'attachable');
    }

    public function findings()
    {
        return $this->hasMany(HrPoshFinding::class, 'case_id');
    }

    /**
     * The committee this case belongs to.
     *
     * A plain relation over a snapshot column — there is no database foreign
     * key, so a committee removed later leaves this returning null rather than
     * blocking the deletion or cascading into the case.
     */
    public function committee()
    {
        return $this->belongsTo(HrPoshCommittee::class, 'committee_id');
    }
}
