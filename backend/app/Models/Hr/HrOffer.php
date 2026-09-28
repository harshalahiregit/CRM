<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrOffer extends Model
{
    use Auditable;

    protected $table = 'hr_offers';

    /** Pre-joining checklist template (initialised on offer acceptance). */
    public const PRE_JOINING_TEMPLATE = [
        ['key' => 'cancelled_cheque', 'label' => 'Upload cancelled cheque',        'type' => 'file'],
        ['key' => 'vaccination',      'label' => 'Upload vaccination certificate',  'type' => 'file'],
        ['key' => 'pf_details',       'label' => 'Fill PF details',                 'type' => 'data'],
        ['key' => 'esic',             'label' => 'Fill ESIC details',               'type' => 'data'],
        ['key' => 'nominee',          'label' => 'Nominee details',                 'type' => 'data'],
        ['key' => 'policies',         'label' => 'Read company policies',           'type' => 'ack'],
        ['key' => 'nda',              'label' => 'Accept NDA',                      'type' => 'ack'],
    ];

    protected $fillable = [
        'candidate_id','tenant_id','position','department','offered_ctc',
        'salary_structure_id','salary_breakdown',
        'joining_date','probation_period','notice_period','validity_date',
        'status','letter_path','sent_at','accepted_at','rejection_reason',
        // Offer portal (Sprint 2). access_token is NOT fillable any more and
        // token_hash never was: the credential is written only by
        // OfferPortalToken, through forceFill. Leaving them mass-assignable
        // would let any future update() put a plaintext token back in the
        // column this hardening exists to empty.
        'generated_at','viewed_at','declined_at','expired_at','joining_confirmed_at',
        'accepted_ip','accepted_device','accepted_browser','accepted_name','accepted_signature','clarification','clarification_at','pre_joining',
        // Lifecycle: approval / withdraw / versioning.
        'submitted_for_approval_at','approved_by','approved_at','withdrawn_at','withdraw_reason','version',
    ];

    /**
     * The portal credential never leaves the server.
     *
     * access_token is the emptied legacy plaintext column and token_hash is the
     * credential itself; neither belongs in a response. This model is returned
     * directly by OfferController@show/store/send/updateStatus/regenerate/
     * withdraw/revise/extend and by OfferService::list(), so hiding it here is
     * what keeps it out of all nine at once rather than relying on every future
     * endpoint to remember.
     *
     * A hash is not a secret in the way the raw token is, but publishing it
     * would hand an attacker the exact value to search a stolen database dump
     * for, and nothing in the product has any use for it.
     */
    protected $hidden = ['access_token', 'token_hash'];

    protected $casts = [
        'joining_date'         => 'date',
        'token_issued_at'      => 'datetime',
        'token_revoked_at'     => 'datetime',
        'validity_date'        => 'date',
        'sent_at'              => 'datetime',
        'accepted_at'          => 'datetime',
        'generated_at'         => 'datetime',
        'viewed_at'            => 'datetime',
        'declined_at'          => 'datetime',
        'expired_at'           => 'datetime',
        'joining_confirmed_at' => 'datetime',
        'clarification_at'     => 'datetime',
        'submitted_for_approval_at' => 'datetime',
        'approved_at'          => 'datetime',
        'withdrawn_at'         => 'datetime',
        'version'              => 'integer',
        'offered_ctc'          => 'decimal:2',
        'pre_joining'          => 'array',
        'salary_breakdown'     => 'array',
    ];

    /**
     * Offer lifecycle state machine (no-skip). `status` is a free string in the DB;
     * this map is the single source of truth for legal transitions. `Generated` is
     * the legacy "ready to send" state and is treated like `Approved`.
     */
    public const TRANSITIONS = [
        'Draft'            => ['Pending Approval', 'Approved', 'Withdrawn'],
        'Pending Approval' => ['Approved', 'Draft', 'Withdrawn'],
        'Approved'         => ['Sent', 'Withdrawn'],
        'Generated'        => ['Sent', 'Pending Approval', 'Withdrawn'],
        'Sent'             => ['Viewed', 'Accepted', 'Declined', 'Expired', 'Withdrawn'],
        'Viewed'           => ['Accepted', 'Declined', 'Expired', 'Withdrawn'],
        'Accepted'         => ['Completed'],
        'Declined'         => [],
        'Expired'          => ['Sent', 'Approved'],
        'Withdrawn'        => [],
        'Completed'        => [],
    ];

    /** Statuses at which an offer may still be withdrawn or revised (before acceptance). */
    public const PRE_ACCEPTANCE = ['Draft', 'Pending Approval', 'Approved', 'Generated', 'Sent', 'Viewed', 'Expired'];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function salaryStructure()
    {
        return $this->belongsTo(\App\Models\Hr\HrSalaryStructure::class, 'salary_structure_id');
    }

    /** Immutable version history — every superseded revision, newest first. */
    public function revisions()
    {
        return $this->hasMany(HrOfferRevision::class, 'offer_id')->orderByDesc('version');
    }

    /** Past its validity date and not yet resolved. */
    public function isPastValidity(): bool
    {
        return $this->validity_date
            && $this->validity_date->isPast()
            && in_array($this->status, ['Generated', 'Sent', 'Viewed'], true);
    }
}
