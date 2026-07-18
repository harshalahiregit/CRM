<?php

namespace App\Http\Requests\Compliance;

use App\Support\Compliance\QuestionType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Everything optional — a caller may correct just the description. Whether the
 * definition may change at all is a lifecycle rule, so it is enforced in
 * ComplianceTemplateService::update, not here.
 */
class UpdateComplianceTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => 'sometimes|required|string|max:180',
            'category'    => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',

            'definition'                        => 'sometimes|array',
            'definition.sections'               => 'required_with:definition|array|min:1',
            'definition.sections.*.key'         => 'required|string|max:60',
            'definition.sections.*.title'       => 'required|string|max:180',
            'definition.sections.*.questions'   => 'required|array|min:1',
            'definition.sections.*.questions.*.key'   => 'required|string|max:60',
            'definition.sections.*.questions.*.label' => 'required|string|max:400',
            'definition.sections.*.questions.*.type'  => 'required|string|in:'.implode(',', QuestionType::ALL),

            'thresholds'          => 'sometimes|array',
            'thresholds.moderate' => 'nullable|numeric|min:0|max:100',
            'thresholds.high'     => 'nullable|numeric|min:0|max:100',
        ];
    }

    /**
     * See StoreComplianceTemplateRequest::validated — validated() would strip
     * every unruled scoring field out of the definition.
     */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated($key, $default);

        if ($this->has('definition')) {
            $data['definition'] = $this->input('definition');
        }

        return $data;
    }
}
