<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CannedResponse extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'title', 'category', 'shortcut', 'content', 'created_by', 'use_count',
    ];

    protected $casts = [
        'use_count' => 'integer',
    ];
}
