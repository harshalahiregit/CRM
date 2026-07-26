<?php

namespace App\Models\Inventory;

class Subgroup extends Setting
{
    protected $table = 'inventory_subgroups';

    protected $fillable = ['tenant_id', 'group_id', 'name', 'order'];

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}
