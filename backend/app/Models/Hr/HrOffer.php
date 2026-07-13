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
        'joining_date','probation_period','notice_period','validity_date',
        'status','letter_path','sent_at','accepted_at','rejection_reason',
        // Offer portal (Sprint 2)
        'access_token','generated_at','viewed_at','declined_at','expired_at','joining_confirmed_at',
        'accepted_ip','accepted_device','accepted_browser','accepted_name','accepted_signature','clarification','clarification_at','pre_joining',
    ];

    protected $casts = [
        'joining_date'         => 'date',
        'validity_date'        => 'date',
        'sent_at'              => 'datetime',
        'accepted_at'          => 'datetime',
        'generated_at'         => 'datetime',
        'viewed_at'            => 'datetime',
        'declined_at'          => 'datetime',
        'expired_at'           => 'datetime',
        'joining_confirmed_at' => 'datetime',
        'clarification_at'     => 'datetime',
        'offered_ctc'          => 'decimal:2',
        'pre_joining'          => 'array',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    /** Past its validity date and not yet resolved. */
    public function isPastValidity(): bool
    {
        return $this->validity_date
            && $this->validity_date->isPast()
            && in_array($this->status, ['Generated', 'Sent', 'Viewed'], true);
    }
}
