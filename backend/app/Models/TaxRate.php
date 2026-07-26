<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'rate', 'active'];

    protected $casts = [
        'rate'   => 'decimal:2',
        'active' => 'boolean',
    ];
}
