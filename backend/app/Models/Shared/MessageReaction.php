<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One emoji reaction by one user on one message-like row. */
class MessageReaction extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'subject_type', 'subject_id', 'user_id', 'emoji'];

    protected $casts = [
        'subject_id' => 'integer',
    ];
}
