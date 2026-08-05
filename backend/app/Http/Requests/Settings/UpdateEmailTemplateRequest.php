<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for an email template. Semantic validation (merge fields,
 * renderability) belongs to EmailTemplateService::validate() so the rules live in
 * one place. Nulls are coalesced first because Laravel's global
 * ConvertEmptyStringsToNull turns a cleared field into null.
 */
class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['body_html', 'body_text', 'description'] as $key) {
            if ($this->exists($key) && $this->input($key) === null) {
                $this->merge([$key => '']);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'required', 'string', 'max:150'],
            'subject'     => ['sometimes', 'required', 'string', 'max:255'],
            'body_html'   => ['nullable', 'string', 'max:120000'],
            'body_text'   => ['nullable', 'string', 'max:60000'],
            'description' => ['nullable', 'string', 'max:500'],
            'enabled'     => ['nullable', 'boolean'],
        ];
    }
}
