<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class RecordInterviewFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // "Skipped" marks an OPTIONAL round (e.g. Client Round) as not required.
            // It is neither Failed nor Cancelled: the round closes as Completed so the
            // sequence gate lets the next round through, and the stage engine leaves the
            // candidate untouched (no branch matches "Skipped").
            'result'                => 'required|in:Pending,Passed,Failed,On Hold,Next Round,Skipped',
            'recommendation'        => 'nullable|in:Strong Hire,Hire,Neutral,No Hire',
            'rating'                => 'nullable|integer|min:1|max:5',
            'notes'                 => 'nullable|string',
            'status'                => 'in:Scheduled,Completed,Cancelled,Rescheduled',
            'technical_score'       => 'nullable|integer|min:0|max:10',
            'communication_score'   => 'nullable|integer|min:0|max:10',
            'problem_solving_score' => 'nullable|integer|min:0|max:10',
            // Structured interviewer ratings (Doc 3 Module 6)
            'knowledge_score'       => 'nullable|integer|min:0|max:10',
            'confidence_score'      => 'nullable|integer|min:0|max:10',
            'ownership_score'       => 'nullable|integer|min:0|max:10',
            'learning_ability_score' => 'nullable|integer|min:0|max:10',
            'decision_making_score' => 'nullable|integer|min:0|max:10',
            'leadership_score'      => 'nullable|integer|min:0|max:10',
            'integrity_score'       => 'nullable|integer|min:0|max:10',
            'culture_fit_score'     => 'nullable|integer|min:0|max:10',
            'strengths'             => 'nullable|string|max:2000',
            'concerns'              => 'nullable|string|max:2000',
            // Completion metadata (Complete-Interview form).
            'attendance'            => 'nullable|in:Present,Absent,No Show',
            'duration'              => 'nullable|integer|min:0|max:1440',
            'remarks'               => 'nullable|string|max:2000',
        ];
    }
}
