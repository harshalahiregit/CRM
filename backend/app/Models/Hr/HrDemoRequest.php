<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * An inbound request for a demo.
 *
 * No BelongsToTenant: these arrive before anybody knows which workspace they
 * belong to, so tenant_id is nullable and set when somebody claims it. A global
 * scope would hide every unclaimed enquiry from everyone, which is the opposite
 * of what an inbound queue is for.
 */
class HrDemoRequest extends Model
{
    protected $table = 'hr_demo_requests';

    public const STATUSES = ['new', 'contacted', 'scheduled', 'converted', 'declined'];

    protected $fillable = [
        'tenant_id', 'name', 'company_name', 'email', 'phone', 'address',
        'num_employees', 'message', 'notes', 'status', 'demo_at',
        'assigned_to', 'updated_by', 'source',
    ];

    protected $casts = [
        'num_employees' => 'integer',
        'demo_at'       => 'datetime',
    ];

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
