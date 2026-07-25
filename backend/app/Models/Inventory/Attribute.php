<?php

namespace App\Models\Inventory;

/**
 * One table for all four variation attributes, keyed by `kind`.
 * Colour rows use `value` for a hex code; the rest leave it null.
 */
class Attribute extends Setting
{
    protected $table = 'inventory_attributes';

    public const KINDS = ['color', 'model', 'size', 'style'];

    protected $fillable = ['tenant_id', 'kind', 'name', 'value', 'order'];

    public function scopeKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }
}
