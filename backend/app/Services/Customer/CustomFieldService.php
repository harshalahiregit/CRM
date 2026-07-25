<?php

namespace App\Services\Customer;

use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Customer\CustomField;
use App\Models\Customer\CustomFieldValue;
use Illuminate\Support\Collection;

/**
 * Generic custom-field engine — definitions + per-record values, scoped by
 * `field_to` (entity type). Built for the Customer module but reusable by any
 * module that passes its own field_to key.
 */
class CustomFieldService
{
    /** List field definitions for an entity type. */
    public function definitions(int $tenantId, string $fieldTo, bool $onlyActive = true): Collection
    {
        return CustomField::forTenant($tenantId)
            ->for($fieldTo)
            ->when($onlyActive, fn ($q) => $q->active())
            ->ordered()
            ->get();
    }

    public function createDefinition(array $data, int $tenantId): CustomField
    {
        return CustomField::create([...$data, 'tenant_id' => $tenantId]);
    }

    public function updateDefinition(CustomField $field, array $data, int $tenantId): CustomField
    {
        $this->assertTenant($field, $tenantId);
        $field->update($data);
        return $field->fresh();
    }

    public function deleteDefinition(CustomField $field, int $tenantId): void
    {
        $this->assertTenant($field, $tenantId);
        $field->delete(); // cascades values via FK
    }

    /** Apply a new field ordering (only touches this tenant's fields). */
    public function reorder(array $ids, int $tenantId): void
    {
        foreach (array_values($ids) as $order => $id) {
            CustomField::forTenant($tenantId)->whereKey($id)->update(['field_order' => $order]);
        }
    }

    /**
     * Values for one record, keyed by field slug, merged with definitions so
     * the frontend can render inputs even when no value exists yet.
     *
     * @return array<int, array{id:int, slug:string, name:string, type:string, required:bool, options:array, value:mixed}>
     */
    public function valuesFor(int $tenantId, string $fieldTo, int $relId, bool $isAdmin = true): array
    {
        $fields = $this->definitions($tenantId, $fieldTo)
            ->when(! $isAdmin, fn ($c) => $c->reject(fn (CustomField $f) => $f->only_admin))
            ->values();

        $stored = CustomFieldValue::forTenant($tenantId)
            ->where('field_to', $fieldTo)
            ->where('rel_id', $relId)
            ->pluck('value', 'field_id');

        return $fields->map(fn (CustomField $f) => [
            'id'             => $f->id,
            'slug'           => $f->slug,
            'name'           => $f->name,
            'type'           => $f->type,
            'required'       => $f->required,
            'options'        => $f->optionList(),
            'bs_column'      => $f->bs_column,
            'display_inline' => $f->display_inline,
            'default_value'  => $f->default_value,
            'value'          => $this->decodeValue($f->type, $stored[$f->id] ?? $f->default_value),
        ])->all();
    }

    /** Multi-value types (multiselect + checkbox groups) are stored JSON-encoded. */
    private function decodeValue(string $type, $value)
    {
        if (in_array($type, ['multiselect', 'checkbox'], true) && is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $value;
        }
        return $value;
    }

    /**
     * Persist submitted values for a record. `$values` maps field_id => value.
     * Unknown/foreign-tenant field ids are ignored.
     */
    public function saveValues(int $tenantId, string $fieldTo, int $relId, array $values): void
    {
        if (empty($values)) {
            return;
        }

        $validIds = CustomField::forTenant($tenantId)->for($fieldTo)->pluck('id')->flip();

        foreach ($values as $fieldId => $value) {
            if (! $validIds->has((int) $fieldId)) {
                continue;
            }

            CustomFieldValue::updateOrCreate(
                ['field_id' => (int) $fieldId, 'rel_id' => $relId],
                [
                    'tenant_id' => $tenantId,
                    'field_to'  => $fieldTo,
                    'value'     => is_array($value) ? json_encode($value) : $value,
                ],
            );
        }
    }

    private function assertTenant(CustomField $field, int $tenantId): void
    {
        if ($field->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException('Unauthorized');
        }
    }
}
