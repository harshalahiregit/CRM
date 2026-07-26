<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One physical verification: a scope of stock, frozen into lines, walked by
 * somebody and approved by somebody else. See the migration for why the variance
 * is measured against a count-time snapshot rather than the raise-time one.
 */
class CountSession extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'inventory_count_sessions';

    public const STATUSES = ['draft', 'counting', 'pending_approval', 'approved', 'cancelled'];

    /** Nothing may be counted or edited once the session is in one of these. */
    public const CLOSED = ['approved', 'cancelled'];

    /** How the lines were chosen. */
    public const SCOPES = ['full', 'location', 'category', 'abc', 'product', 'random'];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'warehouse_id', 'scope', 'scope_ref', 'status',
        'blind', 'assigned_to', 'created_by', 'started_at', 'submitted_at',
        'approved_at', 'approved_by', 'rejected_at', 'rejected_by', 'rejection_reason',
        'variance_value', 'note',
    ];

    protected $casts = [
        'scope_ref'      => 'array',
        'blind'          => 'boolean',
        'started_at'     => 'datetime',
        'submitted_at'   => 'datetime',
        'approved_at'    => 'datetime',
        'rejected_at'    => 'datetime',
        'variance_value' => 'decimal:2',
    ];

    public function lines()
    {
        return $this->hasMany(CountLine::class, 'count_session_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function counter()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }
}
