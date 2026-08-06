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
            // #15 — present designation and department, auto-filled from the
            // LinkedIn parse and editable before save.
            'current_designation' => 'nullable|string|max:255',
            'current_department'  => 'nullable|string|max:255',
            // #15 — the reference. An internal referrer is an employee id; an
            // external one is just a name. Tenant scoping is enforced in the
            // service, which is where the name is resolved from.
            'referred_by_id'      => 'nullable|integer',
            'referred_by_name'    => 'nullable|string|max:255',
            'experience_years' => 'nullable|numeric',
            'education'        => 'nullable|array',
            'certifications'   => 'nullable|array',
            'languages'        => 'nullable|array',
            'professional_references' => 'nullable|array',
            // #15 — still required for every existing caller (they all send it),
            // but a referral may omit it: naming the referrer IS the source, and
            // the service derives 'Employee Referral' from it.
            'source'           => 'required_without:referred_by_id|nullable|string',
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
