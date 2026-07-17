<?php

namespace App\Models\Inventory;

class Group extends Setting
{
    protected $table = 'inventory_groups';

    /** Sub-groups are dependent on the group — the Item form chains the dropdowns. */
    public function subgroups()
    {
        return $this->hasMany(Subgroup::class, 'group_id')->orderBy('order');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'group_id');
    }
}
