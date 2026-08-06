<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Shared\ReactionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Emoji reactions on message threads (owner: Shivam). One shared endpoint for
 * task comments, ticket replies and discussion comments.
 */
class ReactionController extends Controller
{
    use ApiResponse;

    public function __construct(private ReactionService $reactions)
    {
    }

    /** Batch summary for a whole thread: ?subject_type=&subject_ids[]=… */
    public function index(Request $request)
    {
        $data = $request->validate([
            'subject_type'   => ['required', Rule::in(ReactionService::SUBJECTS)],
            'subject_ids'    => ['required', 'array', 'max:500'],
            'subject_ids.*'  => ['integer'],
        ]);

        $summary = $this->reactions->summaryFor($data['subject_type'], $data['subject_ids'], $request->user());

        return $this->success($summary, 'Reactions retrieved');
    }

    public function toggle(Request $request)
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(ReactionService::SUBJECTS)],
            'subject_id'   => ['required', 'integer'],
            'emoji'        => ['required', 'string', Rule::in(ReactionService::EMOJIS)],
        ]);

        $summary = $this->reactions->toggle($data['subject_type'], (int) $data['subject_id'], $data['emoji'], $request->user());

        return $this->success($summary, 'Reaction updated');
    }
}
