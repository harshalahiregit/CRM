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

    /**
     * Values for one record, keyed by field slug, merged with definitions so
     * the frontend can render inputs even when no value exists yet.
     *
     * @return array<int, array{id:int, slug:string, name:string, type:string, required:bool, options:array, value:mixed}>
     */
    public function valuesFor(int $tenantId, string $fieldTo, int $relId): array
    {
        $fields = $this->definitions($tenantId, $fieldTo);
        $stored = CustomFieldValue::forTenant($tenantId)
            ->where('field_to', $fieldTo)
            ->where('rel_id', $relId)
            ->pluck('value', 'field_id');

        return $fields->map(fn (CustomField $f) => [
            'id'       => $f->id,
            'slug'     => $f->slug,
            'name'     => $f->name,
            'type'     => $f->type,
            'required' => $f->required,
            'options'  => $f->optionList(),
            'value'    => $stored[$f->id] ?? $f->default_value,
        ])->all();
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
