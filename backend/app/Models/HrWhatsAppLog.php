<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrWhatsAppLog extends Model
{
    protected $table = 'hr_whatsapp_logs';
    
    protected $fillable = [
        'tenant_id',
        'candidate_id',
        'to_number',
        'message_sid',
        'event_type',
        'message',
        'status',
        'error_message',
        'sent_at',
        'delivered_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Get the candidate that owns the log.
     */
    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    /**
     * Scope to filter by status.
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by event type.
     */
    public function scopeEventType($query, $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Check if message was delivered.
     */
    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    /**
     * Check if message failed.
     */
    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'undelivered']);
    }
}
