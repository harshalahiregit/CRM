<?php

namespace App\Http\Requests\Settings;

use App\Support\Settings\SettingRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Generic, registry-driven validation for a settings group. The group comes from
 * the route ({group}); rules for each key are pulled from SettingRegistry, so
 * validation stays in one place and never duplicates the schema. Payload shape:
 * { values: { <key>: <value>, … } }.
 */
class UpdateSettingsGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $group = (string) $this->route('group');

        $rules = ['values' => ['required', 'array']];
        foreach (SettingRegistry::definition()[$group] ?? [] as $key => $meta) {
            $rules["values.{$key}"] = $meta['rules'] ?? ['nullable'];
        }

        return $rules;
    }
}
