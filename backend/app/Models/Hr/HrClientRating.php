<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Client rating for a recruitment engagement (Phase 3). One per hiring request,
 * submitted from the public client-tracking portal. Feeds recruiter performance.
 */
class HrClientRating extends Model
{
    protected $table = 'hr_client_ratings';

    protected $fillable = [
        'tenant_id', 'hiring_request_id', 'external_company_id', 'recruiter_id',
        'overall', 'communication', 'candidate_quality', 'turnaround_time', 'feedback',
    ];

    protected $casts = [
        'overall'           => 'integer',
        'communication'     => 'integer',
        'candidate_quality' => 'integer',
        'turnaround_time'   => 'integer',
    ];

    public function hiringRequest()
    {
        return $this->belongsTo(HrHiringRequest::class, 'hiring_request_id');
    }

    public function recruiter()
    {
        return $this->belongsTo(User::class, 'recruiter_id');
    }
}
