<?php

namespace App\Http\Requests\Compliance;

use App\Support\Compliance\ChecklistSubject;
use Illuminate\Foundation\Http\FormRequest;

class IssueChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_id' => 'required|exists:compliance_templates,id',

            // A stable key from the allowlist — never a class name. Both or
            // neither: an id without a type has nothing to attach to.
            'subject_type' => 'nullable|string|in:'.implode(',', array_keys(ChecklistSubject::MAP)).'|required_with:subject_id',
            'subject_id'   => 'nullable|integer|required_with:subject_type',

            'title'     => 'nullable|string|max:200',
            'reference' => 'nullable|string|max:80',

            // Either an internal user or a plain name/email — a vendor's site
            // supervisor often has no login at all.
            'assigned_to'    => 'nullable|exists:users,id',
            'assignee_name'  => 'nullable|string|max:120',
            'assignee_email' => 'nullable|email|max:180',

            'due_date' => 'nullable|date|after_or_equal:today',
            'remarks'  => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => 'A checklist cannot be due before it is issued.',
        ];
    }
}
