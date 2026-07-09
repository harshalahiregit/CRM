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
            'result'                => 'required|in:Pending,Passed,Failed,On Hold',
            'notes'                 => 'nullable|string',
            'status'                => 'in:Scheduled,Completed,Cancelled,Rescheduled',
            'technical_score'       => 'nullable|integer|min:0|max:10',
            'communication_score'   => 'nullable|integer|min:0|max:10',
            'problem_solving_score' => 'nullable|integer|min:0|max:10',
        ];
    }
}
