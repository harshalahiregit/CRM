<?php

namespace App\Http\Controllers\Api\Project;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Project\DiscussionComment;
use App\Models\Project\ProjectDiscussion;
use App\Services\Project\ProjectService;
use Illuminate\Http\Request;

/**
 * Discussions tab (owner: Shivam). Reads and starting a discussion are open to
 * any project viewer (mirrors the Notes tab template — anyone who can see the
 * project can collaborate); deleting a discussion is a manage action. Access is
 * delegated to ProjectService so this tab never diverges from the workspace.
 */
class ProjectDiscussionController extends Controller
{
    use ApiResponse;

    public function __construct(private ProjectService $projects)
    {
    }

    private function isAdmin(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }

    private function guardView(Request $request, int $project): void
    {
        $this->projects->assertProjectVisible($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
    }

    private function guardManage(Request $request, int $project): void
    {
        $this->projects->assertProjectManage($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));
    }

    /** A discussion scoped to this tenant + project, or a 404. */
    private function findDiscussion(int $tenantId, int $project, int $discussion): ProjectDiscussion
    {
        $row = ProjectDiscussion::forTenant($tenantId)->where('project_id', $project)->find($discussion);
        if (! $row) {
            throw new BusinessException('Discussion not found.', 404);
        }

        return $row;
    }

    /** List discussions with comment_count and last_activity (newest activity first). */
    public function index(Request $request, int $project)
    {
        $this->guardView($request, $project);
        $tenantId = $request->user()->tenant_id;

        $discussions = ProjectDiscussion::forTenant($tenantId)
            ->where('project_id', $project)
            ->with('author:id,name')
            ->withCount('comments')
            ->withMax('comments', 'created_at')
            ->get()
            ->map(function (ProjectDiscussion $d) {
                // Latest comment time, else the discussion's own last-touched time.
                $last = $d->comments_max_created_at ?? $d->updated_at;
                $d->setAttribute('comment_count', (int) $d->comments_count);
                $d->setAttribute('last_activity', $last);

                return $d;
            })
            ->sortByDesc('last_activity')
            ->values();

        return $this->success($discussions, 'Discussions retrieved');
    }

    public function store(Request $request, int $project)
    {
        $this->guardView($request, $project);
        $data = $request->validate([
            'subject'             => 'required|string|max:255',
            'body'                => 'nullable|string|max:20000',
            'visible_to_customer' => 'nullable|boolean',
        ]);

        $discussion = ProjectDiscussion::create([
            'tenant_id'           => $request->user()->tenant_id,
            'project_id'          => $project,
            'subject'             => $data['subject'],
            'body'                => $data['body'] ?? null,
            'visible_to_customer' => $data['visible_to_customer'] ?? false,
            'created_by'          => $request->user()->id,
        ]);

        $discussion->setAttribute('comment_count', 0);
        $discussion->setAttribute('last_activity', $discussion->updated_at);

        return $this->success($discussion->load('author:id,name'), 'Discussion created', 201);
    }

    public function destroy(Request $request, int $project, int $discussion)
    {
        $this->guardManage($request, $project);
        $this->findDiscussion($request->user()->tenant_id, $project, $discussion)->delete();

        return $this->success(null, 'Discussion deleted');
    }

    /** Comments under a discussion — any project viewer may read them. */
    public function listComments(Request $request, int $project, int $discussion)
    {
        $this->guardView($request, $project);
        $row = $this->findDiscussion($request->user()->tenant_id, $project, $discussion);

        return $this->success($row->comments()->with('user:id,name')->get(), 'Comments retrieved');
    }

    /** Add a comment — allowed for any user who can VIEW the project. */
    public function addComment(Request $request, int $project, int $discussion)
    {
        $this->guardView($request, $project);
        $row = $this->findDiscussion($request->user()->tenant_id, $project, $discussion);

        $data = $request->validate(['content' => 'required|string|max:20000']);

        $comment = $row->comments()->create([
            'tenant_id' => $request->user()->tenant_id,
            'user_id'   => $request->user()->id,
            'content'   => $data['content'],
        ]);

        // Bump the discussion so last_activity reflects the new comment even if
        // the withMax read races; keeps the list ordering intuitive.
        $row->touch();

        return $this->success($comment->load('user:id,name'), 'Comment added', 201);
    }
}
