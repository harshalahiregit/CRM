<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One opening of one document — see LogDocumentAccess for why it exists and
 * how it is written.
 *
 * Deliberately append-only in practice: nothing in the application updates or
 * deletes a row. An audit trail somebody can tidy is not an audit trail.
 */
class DocumentAccessLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'actor_type', 'actor_label',
        'action', 'document', 'mime', 'route', 'path',
        'subject_type', 'subject_id',
        'ip', 'device', 'browser', 'user_agent', 'location',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** "Rita Bose · Mobile · Chrome · 203.0.113.4 · Pune, IN" */
    public function getContextAttribute(): string
    {
        return implode(' · ', array_filter([
            $this->actor_label,
            $this->device,
            $this->browser,
            $this->ip,
            $this->location,
        ]));
    }
}
