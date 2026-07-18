<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A single message in the company ↔ recruiter thread for a hiring request.
 * sender_kind is 'company' or 'internal'; the ambient portal identity decides it.
 */
class HrHiringRequestMessage extends Model
{
    protected $table = 'hr_hiring_request_messages';

    protected $fillable = [
        'tenant_id', 'hiring_request_id', 'submission_id', 'sender_kind', 'sender_user_id', 'body', 'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function hiringRequest()
    {
        return $this->belongsTo(HrHiringRequest::class, 'hiring_request_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
