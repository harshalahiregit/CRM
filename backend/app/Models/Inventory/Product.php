<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $table = 'inventory_products';

    protected $fillable = [
        'tenant_id', 'sku', 'sku_code', 'sku_name', 'name', 'description', 'barcode',
        'tags', 'custom_fields',
        'parent_id', 'category_id', 'type_id', 'group_id', 'subgroup_id',
        'brand', 'origin', 'model', 'variant', 'size', 'color',
        'color_id', 'model_id', 'size_id', 'style_id',
        'base_unit', 'unit_id', 'weight', 'volume', 'image_path',
        'hsn', 'sac', 'gst_rate', 'tax_id',
        'min_stock', 'max_stock', 'reorder_point', 'safety_stock',
        'track_batch', 'track_serial', 'shelf_life_days', 'warranty_months',
        'cost_price', 'sale_price', 'profit_ratio', 'sales_item_id',
        'staff_id', 'route_point', 'without_checking_warehouse',
        'status', 'created_by',
    ];

    protected $casts = [
        'weight'          => 'decimal:3',
        'volume'          => 'decimal:3',
        'gst_rate'        => 'decimal:2',
        'min_stock'       => 'decimal:3',
        'max_stock'       => 'decimal:3',
        'reorder_point'   => 'decimal:3',
        'safety_stock'    => 'decimal:3',
        'track_batch'     => 'boolean',
        'track_serial'    => 'boolean',
        'shelf_life_days' => 'integer',
        'warranty_months' => 'integer',
        'cost_price'      => 'decimal:2',
        'sale_price'      => 'decimal:2',
        'profit_ratio'    => 'decimal:2',
        // "Do not update inventory numbers" — excludes the item from stock math.
        'without_checking_warehouse' => 'boolean',
        'tags'          => 'array',
        'custom_fields' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /* ── Settings master data ───────────────────────────────────── */

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function type()
    {
        return $this->belongsTo(Type::class, 'type_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function subgroup()
    {
        return $this->belongsTo(Subgroup::class, 'subgroup_id');
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** The four variation attributes all live in inventory_attributes. */
    public function colorAttr()
    {
        return $this->belongsTo(Attribute::class, 'color_id');
    }

    public function modelAttr()
    {
        return $this->belongsTo(Attribute::class, 'model_id');
    }

    public function sizeAttr()
    {
        return $this->belongsTo(Attribute::class, 'size_id');
    }

    public function styleAttr()
    {
        return $this->belongsTo(Attribute::class, 'style_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The on-hand level at or below which this item counts as "low" and should
     * be reordered. The reorder point (falling back to minimum stock) is the
     * trigger, but it can never sit below the safety-stock floor — the buffer you
     * never want to breach. Returns 0 when nothing is configured (never flagged).
     *
     * Requires reorder_point, min_stock and safety_stock to be loaded; partial
     * selects that drive low-stock logic must include all three.
     */
    public function reorderThreshold(): float
    {
        $trigger = (float) ($this->reorder_point ?: $this->min_stock);

        return max($trigger, (float) $this->safety_stock);
    }

    public function stock()
    {
        return $this->hasMany(Stock::class, 'product_id');
    }

    public function movements()
    {
        return $this->hasMany(Movement::class, 'product_id');
    }

    /** Alternate pack units (Box, Carton…) with their factor to the base unit. */
    public function units()
    {
        return $this->hasMany(ProductUnit::class, 'product_id')->orderBy('order')->orderBy('id');
    }
}
