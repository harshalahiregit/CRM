<?php

namespace App\Http\Controllers\Api\Shared;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Shared\Poll;
use App\Services\Shared\PollService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Polls attached to a task / ticket / project (owner: Shivam). One shared
 * endpoint serves every module's "Poll" composer button; PollService delegates
 * visibility to whichever module owns the context.
 */
class PollController extends Controller
{
    use ApiResponse;

    public function __construct(private PollService $polls)
    {
    }

    /** Poll scoped to the caller's tenant, or a 404 — never a cross-tenant read. */
    private function find(Request $request, int $poll): Poll
    {
        $row = Poll::forTenant($request->user()->tenant_id)->find($poll);
        if (! $row) {
            throw new BusinessException('Poll not found.', 404);
        }

        return $row;
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'context_type' => ['required', Rule::in(PollService::CONTEXTS)],
            'context_id'   => ['required', 'integer'],
        ]);

        $polls = $this->polls->listForContext($data['context_type'], (int) $data['context_id'], $request->user());

        return $this->success($polls, 'Polls retrieved');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'context_type'   => ['required', Rule::in(PollService::CONTEXTS)],
            'context_id'     => ['required', 'integer'],
            'question'       => ['required', 'string', 'max:500'],
            'options'        => ['required', 'array', 'min:2', 'max:12'],
            'options.*'      => ['required', 'string', 'max:255'],
            'allow_multiple' => ['nullable', 'boolean'],
            'closes_at'      => ['nullable', 'date', 'after:now'],
        ]);

        $poll = $this->polls->create($data['context_type'], (int) $data['context_id'], $request->user(), $data);

        return $this->success($poll, 'Poll created', 201);
    }

    public function vote(Request $request, int $poll)
    {
        $data = $request->validate([
            'option_ids'   => ['present', 'array'],
            'option_ids.*' => ['integer'],
        ]);

        $row = $this->find($request, $poll);
        $result = $this->polls->vote($row, $request->user(), $data['option_ids']);

        return $this->success($result, 'Vote recorded');
    }

    public function close(Request $request, int $poll)
    {
        $result = $this->polls->close($this->find($request, $poll), $request->user());

        return $this->success($result, 'Poll closed');
    }

    public function destroy(Request $request, int $poll)
    {
        $this->polls->deletePoll($this->find($request, $poll), $request->user());

        return $this->success(null, 'Poll deleted');
    }
}
