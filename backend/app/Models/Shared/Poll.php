<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A poll hung on a task / ticket / project (see the polls migration). Options
 * and votes cascade with it. `context_type` + `context_id` say what it belongs
 * to; the PollService resolves visibility through the owning module.
 */
class Poll extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'context_type', 'context_id',
        'question', 'allow_multiple', 'is_anonymous', 'closes_at', 'created_by',
    ];

    protected $casts = [
        'context_id'     => 'integer',
        'allow_multiple' => 'boolean',
        'is_anonymous'   => 'boolean',
        'closes_at'      => 'datetime',
    ];

    public function options()
    {
        return $this->hasMany(PollOption::class)->orderBy('position')->orderBy('id');
    }

    public function votes()
    {
        return $this->hasMany(PollVote::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** A poll is closed once its deadline has passed (open if it has none). */
    public function isClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }
}
