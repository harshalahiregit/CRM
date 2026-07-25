<?php

namespace App\Http\Requests\Compliance;

use App\Support\Compliance\QuestionType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape-only validation. The deep rules — unique question keys, options on a
 * choice, a cap on a scored number — live in ChecklistEvaluator::validateDefinition
 * so the authoring API and any future importer/seeder enforce one ruleset.
 */
class StoreComplianceTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:180',
            'code'        => 'nullable|string|max:40',
            'category'    => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',

            'definition'                        => 'required|array',
            'definition.sections'               => 'required|array|min:1',
            'definition.sections.*.key'         => 'required|string|max:60',
            'definition.sections.*.title'       => 'required|string|max:180',
            'definition.sections.*.questions'   => 'required|array|min:1',
            'definition.sections.*.questions.*.key'   => 'required|string|max:60',
            'definition.sections.*.questions.*.label' => 'required|string|max:400',
            'definition.sections.*.questions.*.type'  => 'required|string|in:'.implode(',', QuestionType::ALL),

            // Percent-of-maximum cut-offs; RiskBand fills in whatever is omitted.
            'thresholds'          => 'nullable|array',
            'thresholds.moderate' => 'nullable|numeric|min:0|max:100',
            'thresholds.high'     => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'definition.sections.required' => 'A template needs at least one section of questions.',
        ];
    }

    /**
     * Pass the definition through whole.
     *
     * validated() returns ONLY keys that carry a rule, so the per-question
     * scoring metadata — weight, critical, risk_when, options, risk_per_unit —
     * would be silently stripped, saving an inert template that scores every
     * submission 0/0 and bands nothing. Enumerating those fields here is not the
     * fix either: their shape is per-type and open-ended. The definition's deep
     * contract belongs to ChecklistEvaluator::validateDefinition, which the
     * service runs before persisting.
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
