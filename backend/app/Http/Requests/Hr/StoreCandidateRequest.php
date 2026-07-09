<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => 'required|string|max:200',
            'email'            => 'nullable|email',
            'phone'            => 'nullable|string|max:20',
            'location'         => 'nullable|string',
            'current_company'  => 'nullable|string',
            'experience_years' => 'nullable|numeric',
            'source'           => 'required|string',
            'stage'            => 'in:Applied,Screening,Assessment,Interview,Offer,Hired,Rejected',
            'job_posting_id'   => 'nullable|exists:hr_job_postings,id',
            'linkedin_url'     => 'nullable|url',
            'skills'           => 'nullable|array',
            'ai_score'         => 'nullable|integer|min:0|max:100',
            'notes'            => 'nullable|string',
        ];
    }
}
