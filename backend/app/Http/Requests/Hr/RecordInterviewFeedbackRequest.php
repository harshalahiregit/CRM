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
            'result'                => 'required|in:Pending,Passed,Failed,On Hold,Next Round',
            'recommendation'        => 'nullable|in:Strong Hire,Hire,Neutral,No Hire',
            'rating'                => 'nullable|integer|min:1|max:5',
            'notes'                 => 'nullable|string',
            'status'                => 'in:Scheduled,Completed,Cancelled,Rescheduled',
            'technical_score'       => 'nullable|integer|min:0|max:10',
            'communication_score'   => 'nullable|integer|min:0|max:10',
            'problem_solving_score' => 'nullable|integer|min:0|max:10',
        ];
    }
}
