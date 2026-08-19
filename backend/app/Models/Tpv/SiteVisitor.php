<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A gate visitor-register entry (Doc_4 Phase 5/6). */
class SiteVisitor extends Model
{
    use BelongsToTenant;

    protected $table = 'site_visitors';

    protected $fillable = [
        'tenant_id', 'visitor_name', 'company', 'purpose', 'host',
        'contact', 'badge_number', 'check_in_at', 'check_out_at',
    ];

    protected $casts = ['check_in_at' => 'datetime', 'check_out_at' => 'datetime'];
}
