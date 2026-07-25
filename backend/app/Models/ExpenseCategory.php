<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'active'];

    protected $casts = ['active' => 'boolean'];
}
