<?php

namespace App\Http\Requests\Settings;

use App\Support\Settings\SettingRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the General + Branding settings payload against the SettingRegistry —
 * so validation rules live in one place and stay in sync with casts/defaults.
 * Payload shape: { general: {...}, branding: {...} }.
 */
class UpdateGeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'general'  => ['sometimes', 'array'],
            'branding' => ['sometimes', 'array'],
        ], SettingRegistry::rules(['general', 'branding']));
    }
}
