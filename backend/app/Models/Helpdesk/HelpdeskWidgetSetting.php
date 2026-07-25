<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HelpdeskWidgetSetting extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'helpdesk_widget_settings';

    protected $fillable = [
        'tenant_id', 'public_key', 'allowed_origin', 'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];
}
