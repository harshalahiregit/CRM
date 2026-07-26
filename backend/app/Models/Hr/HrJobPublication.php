<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * A record that a Job Posting was published to a distribution channel
 * (Career Portal today; LinkedIn / Naukri / Indeed / TrulyTalents later).
 */
class HrJobPublication extends Model
{
    protected $table = 'hr_job_publications';

    protected $fillable = [
        'tenant_id', 'job_posting_id', 'channel', 'status',
        'external_ref', 'external_url', 'published_at', 'last_synced_at', 'error_message', 'meta',
    ];

    protected $casts = [
        'meta'           => 'array',
        'published_at'   => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function jobPosting()
    {
        return $this->belongsTo(HrJobPosting::class, 'job_posting_id');
    }
}
