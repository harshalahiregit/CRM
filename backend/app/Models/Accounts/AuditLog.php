<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statutory audit trail (Companies Act, since 1 Apr 2023). APPEND-ONLY: no
 * SoftDeletes, no updated_at, and no update/delete routes exist. Records every
 * create/update/cancel/reverse on posted books with before/after snapshots.
 */
class AuditLog extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_audit_logs';

    public $timestamps = false;   // append-only; occurred_at is the only time column

    protected $fillable = [
        'tenant_id', 'user_id', 'entity_type', 'entity_id',
        'action', 'before_json', 'after_json', 'occurred_at', 'ip',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json'  => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
