<?php

namespace App\Models\Inventory;

class Unit extends Setting
{
    protected $table = 'inventory_units';

    protected $fillable = ['tenant_id', 'name', 'short_name', 'order'];

    public function products()
    {
        return $this->hasMany(Product::class, 'unit_id');
    }
}
