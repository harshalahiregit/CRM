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

    // Full legacy field-type set (tblcustomfields.type).
    public const TYPES = [
        'input', 'number', 'textarea', 'select', 'multiselect',
        'checkbox', 'radio', 'date_picker', 'date_picker_time',
        'colorpicker', 'link',
    ];

    // Types whose `options` list is meaningful.
    public const OPTION_TYPES = ['select', 'multiselect', 'checkbox', 'radio'];

    /** Entity types custom fields may attach to (the `field_to` allow-list). */
    public const FIELD_TARGETS = ['customers', 'contacts'];

    protected $fillable = [
        'tenant_id', 'field_to', 'name', 'slug', 'type', 'options',
        'required', 'field_order', 'bs_column', 'only_admin', 'display_inline',
        'active', 'show_on_table', 'default_value',
        'show_on_pdf', 'show_on_client_portal', 'disallow_client_edit', 'show_on_ticket_form',
    ];

    protected $casts = [
        'required'              => 'boolean',
        'active'                => 'boolean',
        'show_on_table'         => 'boolean',
        'only_admin'            => 'boolean',
        'display_inline'        => 'boolean',
        'bs_column'             => 'integer',
        'show_on_pdf'           => 'boolean',
        'show_on_client_portal' => 'boolean',
        'disallow_client_edit'  => 'boolean',
        'show_on_ticket_form'   => 'boolean',
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

    /** Options as an array. Accepts newline- OR comma-separated storage. */
    public function optionList(): array
    {
        if (! $this->options) {
            return [];
        }
        $parts = preg_split('/\r\n|\r|\n/', $this->options);
        // Fall back to comma-splitting single-line (legacy CRM) storage.
        if (count($parts) === 1 && str_contains($this->options, ',')) {
            $parts = explode(',', $this->options);
        }

        return array_values(array_filter(array_map('trim', $parts)));
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
