<?php

namespace App\Http\Requests\Settings;

use App\Support\Numbering\Reset\ResetStrategyRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape/type validation for a numbering configuration. Semantic validation
 * (placeholder correctness, renderability) is the engine's job and runs in
 * DocumentNumberService::validate() — this request only guards the wire format so
 * the two never drift apart.
 */
class UpdateDocumentNumberConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Laravel's global ConvertEmptyStringsToNull turns an empty `suffix`/`prefix`
     * (which the settings form always submits) into NULL, and every column here is
     * NOT NULL — so without this the very first Save would die with a
     * QueryException. Coalesce nulls back to the column defaults before validation.
     */
    protected function prepareForValidation(): void
    {
        $defaults = [
            'prefix' => '', 'suffix' => '', 'padding' => '0',
            'minimum_digits' => 4, 'starting_number' => 1, 'reset_rule' => 'never',
            'enabled' => false, 'locked' => false, 'manual_override' => false, 'decrement_on_delete' => false,
        ];

        $patch = [];
        foreach ($defaults as $key => $default) {
            if ($this->exists($key) && $this->input($key) === null) {
                $patch[$key] = $default;
            }
        }

        if ($patch) {
            $this->merge($patch);
        }
    }

    public function rules(): array
    {
        $rules = app(ResetStrategyRegistry::class)->keys();

        return [
            'format'              => ['required', 'string', 'max:191'],
            'prefix'              => ['nullable', 'string', 'max:30'],
            'suffix'              => ['nullable', 'string', 'max:30'],
            'minimum_digits'      => ['nullable', 'integer', 'min:1', 'max:12'],
            // A DIGIT as the pad character collapses distinct sequences onto the
            // same string (pad '1': seq 111 -> "1111" == seq 1111). Forbid digits.
            'padding'             => ['nullable', 'string', 'size:1', 'regex:/^[^0-9]$|^0$/'],
            'starting_number'     => ['nullable', 'integer', 'min:1'],
            'reset_rule'          => ['nullable', Rule::in($rules)],
            'enabled'             => ['nullable', 'boolean'],
            'locked'              => ['nullable', 'boolean'],
            'manual_override'     => ['nullable', 'boolean'],
            'decrement_on_delete' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'padding.regex' => 'The padding character may not be a digit other than 0 — digits make different sequences render the same number.',
        ];
    }
}
