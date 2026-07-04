<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SalesLineItem extends Model
{
    use HasFactory;

    protected $table = 'sales_line_items';

    protected $fillable = [
        'lineable_type', 'lineable_id', 'item_id',
        'item_name', 'description', 'qty', 'unit',
        'rate', 'tax', 'discount', 'total', 'sort_order',
    ];

    protected $casts = [
        'qty'      => 'decimal:2',
        'rate'     => 'decimal:2',
        'tax'      => 'decimal:2',
        'discount' => 'decimal:2',
        'total'    => 'decimal:2',
    ];

    public function lineable()
    {
        return $this->morphTo();
    }

    public function item()
    {
        return $this->belongsTo(SalesItem::class, 'item_id');
    }

    /**
     * Compute line total: (qty * rate - discount) + tax%
     */
    public static function computeTotal(array $data): float
    {
        $base      = $data['qty'] * $data['rate'];
        $afterDis  = $base - ($data['discount'] ?? 0);
        $taxAmount = $afterDis * ($data['tax'] / 100);
        return round($afterDis + $taxAmount, 2);
    }
}
