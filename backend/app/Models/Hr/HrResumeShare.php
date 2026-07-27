<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A recruiter's share of an existing candidate resume with the client (Phase 3).
 *
 * Owns only the share lifecycle (token + download tracking). The actual file is
 * never copied — download streams the candidate's existing resume on the
 * hr_resumes disk via ResumeService, so document storage is fully reused.
 */
class HrResumeShare extends Model
{
    protected $table = 'hr_resume_shares';

    protected $fillable = [
        'tenant_id', 'hiring_request_id', 'candidate_id', 'share_token',
        'shared_by', 'shared_at', 'downloaded_at', 'download_count',
    ];

    protected $casts = [
        'shared_at'      => 'datetime',
        'downloaded_at'  => 'datetime',
        'download_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $share) {
            if (empty($share->share_token)) {
                $share->share_token = Str::random(48);
            }
        });
    }

    public function hiringRequest()
    {
        return $this->belongsTo(HrHiringRequest::class, 'hiring_request_id');
    }

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function sharedBy()
    {
        return $this->belongsTo(User::class, 'shared_by');
    }
}
