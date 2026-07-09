<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class SalesItem extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $table = 'sales_items';

    protected $fillable = [
        'tenant_id', 'name', 'description', 'long_description',
        'rate', 'unit', 'tax_rate', 'tax_rate_2', 'category',
    ];

    protected $casts = [
        'rate'      => 'decimal:2',
        'tax_rate'  => 'decimal:2',
        'tax_rate_2'=> 'decimal:2',
    ];

}
