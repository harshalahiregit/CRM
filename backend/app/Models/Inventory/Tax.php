<?php

namespace App\Models\Inventory;

class Tax extends Setting
{
    protected $table = 'inventory_taxes';

    protected $fillable = ['tenant_id', 'name', 'rate', 'order'];

    protected $casts = ['rate' => 'decimal:2', 'order' => 'integer'];
}
