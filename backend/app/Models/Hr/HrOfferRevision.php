<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable snapshot of a superseded offer version. Rows are only ever inserted
 * (by OfferService::revise) and never updated or deleted — the offer's full history.
 */
class HrOfferRevision extends Model
{
    protected $table = 'hr_offer_revisions';

    protected $fillable = [
        'offer_id', 'tenant_id', 'version', 'snapshot', 'letter_path',
        'revised_by', 'revised_by_name', 'revision_reason',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'version'  => 'integer',
    ];

    public function offer()
    {
        return $this->belongsTo(HrOffer::class, 'offer_id');
    }

    public function revisedBy()
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
