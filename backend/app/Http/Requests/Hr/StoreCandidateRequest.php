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
            'dob'              => 'nullable|date',
            'location'         => 'nullable|string',
            'address'          => 'nullable|string|max:500',
            'current_company'  => 'nullable|string',
            'experience_years' => 'nullable|numeric',
            'education'        => 'nullable|array',
            'certifications'   => 'nullable|array',
            'languages'        => 'nullable|array',
            'professional_references' => 'nullable|array',
            'source'           => 'required|string',
            'stage'            => 'in:Applied,Screening,Assessment,Interview,Offer,Hired,Rejected',
            'job_posting_id'   => 'nullable|exists:hr_job_postings,id',
            'linkedin_url'     => 'nullable|url',
            'skills'           => 'nullable|array',
            // 'ai_score' is intentionally NOT accepted: it is produced by
            // CandidateScoringEngine and must never be settable by a client.
            'notes'            => 'nullable|string',
        ];
    }
}
