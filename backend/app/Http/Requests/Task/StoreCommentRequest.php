<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Enough room for a comment with a picture in it.
     *
     * This was max:5000 CHARACTERS, and the comment box embeds images inline as
     * base64 — the same rich editor the task description uses. One compressed
     * screenshot is around forty thousand characters, so inserting any image put
     * the comment eight times over the limit and the post came back 422. From
     * the writer's side the button simply did nothing.
     *
     * The description field alongside it has no cap at all; this is the same
     * content authored in the same editor, so it gets a real ceiling rather than
     * one that rules out the feature. Images are downscaled and re-encoded
     * client-side before they are embedded (mediaCompress.js), so this bounds
     * abuse rather than ordinary use.
     */
    private const MAX_CONTENT = 5_000_000;   // ~5 MB of HTML; the column is LONGTEXT

    public function rules(): array
    {
        // A comment needs text OR at least one file — the rich-text box can post
        // either. Files are stored as task files scoped to the comment.
        return [
            'content'  => 'nullable|string|max:'.self::MAX_CONTENT.'|required_without:files',
            'files'    => 'nullable|array|max:10|required_without:content',
            'files.*'  => 'file|max:10240',   // 10 MB each, same ceiling as task/helpdesk files
        ];
    }

    public function messages(): array
    {
        return [
            'content.max' => 'This comment is too large to post. Try fewer or smaller images.',
        ];
    }
}
