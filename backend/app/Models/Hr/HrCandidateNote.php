<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A single entry in a candidate's collaborative notes thread. Tenant-scoped;
 * authored by the HR user who added it.
 */
class HrCandidateNote extends Model
{
    protected $table = 'hr_candidate_notes';

    protected $fillable = [
        'tenant_id', 'candidate_id', 'user_id', 'body', 'visible_to_candidate',
    ];

    protected $casts = [
        'visible_to_candidate' => 'boolean',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
