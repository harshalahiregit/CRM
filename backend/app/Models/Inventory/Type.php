<?php

namespace App\Models\Inventory;

class Type extends Setting
{
    protected $table = 'inventory_types';

    public function products()
    {
        return $this->hasMany(Product::class, 'type_id');
    }
}
