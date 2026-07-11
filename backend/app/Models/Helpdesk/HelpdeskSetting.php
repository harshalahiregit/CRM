<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HelpdeskSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'public_form_enabled', 'public_form_logo_variant', 'default_department_id',
    ];

    protected $casts = [
        'public_form_enabled' => 'boolean',
    ];

    public function defaultDepartment()
    {
        return $this->belongsTo(TicketDepartment::class, 'default_department_id');
    }
}
