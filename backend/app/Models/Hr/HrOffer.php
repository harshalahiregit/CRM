<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

class HrOffer extends Model
{
    protected $table = 'hr_offers';

    protected $fillable = [
        'candidate_id','tenant_id','position','department','offered_ctc',
        'joining_date','probation_period','notice_period','validity_date',
        'status','letter_path','sent_at','accepted_at','rejection_reason',
    ];

    protected $casts = [
        'joining_date'   => 'date',
        'validity_date'  => 'date',
        'sent_at'        => 'datetime',
        'accepted_at'    => 'datetime',
        'offered_ctc'    => 'decimal:2',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }
}
