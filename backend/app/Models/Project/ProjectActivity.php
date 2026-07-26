<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Append-only audit row — no updated_at, never edited after creation. */
class ProjectActivity extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'project_id', 'type', 'description', 'actor_id', 'visible_to_customer', 'created_at',
    ];

    protected $casts = [
        'visible_to_customer' => 'boolean',
        'created_at'          => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
