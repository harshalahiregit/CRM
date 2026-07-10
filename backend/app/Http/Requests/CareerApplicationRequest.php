<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Public Career Portal application — no auth. */
class CareerApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => 'required|string|max:150',
            'email'            => 'required|email|max:150',
            'phone'            => 'required|string|max:30',
            'resume'           => 'required|file|mimes:pdf,doc,docx|max:5120', // 5 MB
            'experience_years' => 'nullable|numeric|min:0|max:60',
            'skills'           => 'nullable',
            'current_company'  => 'nullable|string|max:150',
            'location'         => 'nullable|string|max:150',
            'current_ctc'      => 'nullable|numeric|min:0',
            'expected_ctc'     => 'nullable|numeric|min:0',
            'notice_period'    => 'nullable|string|max:60',
            'linkedin_url'     => 'nullable|url|max:255',
            'cover_note'       => 'nullable|string|max:2000',
        ];
    }
}
