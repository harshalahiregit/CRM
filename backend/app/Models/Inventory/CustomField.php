<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom field DEFINITION (blueprint §9 "Warehouse custom fields" and the
 * Item form's "Custom fields" tab). The values live as a JSON bag on the
 * product/warehouse itself, keyed by this row's `key`.
 */
class CustomField extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_custom_fields';

    /** Which records a field can be attached to. */
    public const ENTITIES = ['product', 'warehouse'];

    /** Input types the form knows how to render. */
    public const TYPES = ['text', 'number', 'date', 'select', 'checkbox'];

    protected $fillable = ['tenant_id', 'entity', 'key', 'label', 'type', 'options', 'required', 'order'];

    protected $casts = [
        'options'  => 'array',
        'required' => 'boolean',
        'order'    => 'integer',
    ];
}
