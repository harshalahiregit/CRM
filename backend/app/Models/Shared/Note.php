<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A note attached to any subject.
 *
 * Shared, not vendor-owned: it attaches polymorphically the same way `reminders`
 * does, so a second module plugs in without a second table.
 */
class Note extends Model
{
    use BelongsToTenant;

    protected $table = 'notes';

    protected $fillable = [
        'tenant_id', 'notable_type', 'notable_id', 'title', 'content', 'created_by',
    ];

    public function notable()
    {
        return $this->morphTo();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForSubject($query, string $type, int $id)
    {
        return $query->where('notable_type', $type)->where('notable_id', $id);
    }
}
