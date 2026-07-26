<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // A comment needs text OR at least one file — the rich-text box can post
        // either. Files are stored as task files scoped to the comment.
        return [
            'content'  => 'nullable|string|max:5000|required_without:files',
            'files'    => 'nullable|array|max:10|required_without:content',
            'files.*'  => 'file|max:10240',   // 10 MB each, same ceiling as task/helpdesk files
        ];
    }
}
