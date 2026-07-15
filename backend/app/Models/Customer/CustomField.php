<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Generic custom-field definition, scoped to an entity type via `field_to`
 * (e.g. 'customers', 'contacts'). Same shape as the legacy tblcustomfields so
 * other modules can reuse this engine.
 */
class CustomField extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'input', 'number', 'textarea', 'select', 'multiselect',
        'checkbox', 'date_picker', 'datetime_picker', 'colorpicker', 'link',
    ];

    /** Entity types custom fields may attach to (the `field_to` allow-list). */
    public const FIELD_TARGETS = ['customers', 'contacts'];

    protected $fillable = [
        'tenant_id', 'field_to', 'name', 'slug', 'type', 'options',
        'required', 'field_order', 'active', 'show_on_table', 'default_value',
    ];

    protected $casts = [
        'required'      => 'boolean',
        'active'        => 'boolean',
        'show_on_table' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (CustomField $field) {
            if (empty($field->slug)) {
                $field->slug = Str::slug($field->name, '_');
            }
        });
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class, 'field_id');
    }

    /** Options as an array (stored newline-separated). */
    public function optionList(): array
    {
        return $this->options
            ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $this->options))))
            : [];
    }

    public function scopeFor($query, string $fieldTo)
    {
        return $query->where('field_to', $fieldTo);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('field_order')->orderBy('id');
    }
}
