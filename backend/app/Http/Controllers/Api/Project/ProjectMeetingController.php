<?php

namespace App\Http\Controllers\Api\Project;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Project\ProjectMeeting;
use App\Services\Project\ProjectService;
use App\Services\Shared\MeetingLinkAnnouncer;
use App\Services\Shared\OnlineMeetingService;
use Illuminate\Http\Request;

/**
 * Kickoff Meeting tab (owner: Shivam). Read guarded by project visibility;
 * writes guarded by project-manage. Access checks are delegated to ProjectService
 * (the same guards the Project workspace uses) so this tab never diverges.
 */
class ProjectMeetingController extends Controller
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

    /** A meeting scoped to this tenant + project, or a 404. */
    private function findMeeting(int $tenantId, int $project, int $meeting): ProjectMeeting
    {
        $row = ProjectMeeting::forTenant($tenantId)->where('project_id', $project)->find($meeting);
        if (! $row) {
            throw new BusinessException('Meeting not found.', 404);
        }

        return $row;
    }

    /** List meetings (newest first) with {total, completed, pending} counters. */
    public function index(Request $request, int $project)
    {
        $this->guardView($request, $project);
        $tenantId = $request->user()->tenant_id;

        $meetings = ProjectMeeting::forTenant($tenantId)
            ->where('project_id', $project)
            ->with('creator:id,name')
            ->latest()
            ->get();

        $counters = [
            'total'     => $meetings->count(),
            'completed' => $meetings->where('status', 'completed')->count(),
            'pending'   => $meetings->where('status', 'pending')->count(),
        ];

        return $this->success(['meetings' => $meetings, 'counters' => $counters], 'Meetings retrieved');
    }

    public function store(Request $request, int $project)
    {
        $this->guardManage($request, $project);
        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'mode'         => 'nullable|in:online,offline,hybrid',
            'meeting_link' => 'nullable|string|max:1000',
            'participants' => 'nullable|string|max:2000',
            'planned_date' => 'nullable|date',
            'meeting_date' => 'nullable|date',
            'status'       => 'nullable|in:pending,completed',
            'mom_sent'     => 'nullable|boolean',
            'notes'        => 'nullable|string|max:20000',
        ]);

        $this->assertJoinableLink($data['meeting_link'] ?? null);

        $meeting = ProjectMeeting::create([
            ...$data,
            'tenant_id'  => $request->user()->tenant_id,
            'project_id' => $project,
            'mode'       => $data['mode'] ?? 'online',
            'status'     => $data['status'] ?? 'pending',
            'mom_sent'   => $data['mom_sent'] ?? false,
            'created_by' => $request->user()->id,
        ]);

        // A project meeting's link is typed straight onto the row, so the moment
        // it is saved is the moment there is a room to share — see
        // MeetingLinkAnnouncer.
        $notified = $meeting->meeting_link
            ? app(MeetingLinkAnnouncer::class)->announceAfterResponse($meeting, $request->user())
            : null;

        // The meeting itself stays the payload — callers already read it
        // directly — with the send report alongside it.
        return $this->success(
            $meeting->load('creator:id,name')->toArray() + ['notified' => $notified],
            'Meeting created', 201);
    }

    public function update(Request $request, int $project, int $meeting)
    {
        $this->guardManage($request, $project);
        $row = $this->findMeeting($request->user()->tenant_id, $project, $meeting);

        $data = $request->validate([
            'title'        => 'sometimes|required|string|max:255',
            'mode'         => 'nullable|in:online,offline,hybrid',
            'meeting_link' => 'nullable|string|max:1000',
            'participants' => 'nullable|string|max:2000',
            'planned_date' => 'nullable|date',
            'meeting_date' => 'nullable|date',
            'status'       => 'nullable|in:pending,completed',
            'mom_sent'     => 'nullable|boolean',
            'notes'        => 'nullable|string|max:20000',
        ]);

        $this->assertJoinableLink($data['meeting_link'] ?? null);

        $before = $row->meeting_link;
        $row->fill($data)->save();

        // Only when the room actually CHANGED. Saving the notes on a meeting
        // should not re-mail the link to everybody, which is how a useful
        // notification becomes one people filter away.
        $notified = ($row->meeting_link && $row->meeting_link !== $before)
            ? app(MeetingLinkAnnouncer::class)->announceAfterResponse($row, $request->user())
            : null;

        return $this->success(
            $row->fresh('creator:id,name')->toArray() + ['notified' => $notified],
            'Meeting updated');
    }

    /**
     * A link that is a room, not a "start a new meeting" button.
     *
     * meet.google.com/new opens a DIFFERENT, empty meeting for every person who
     * clicks it, so a meeting saved with it invites five people into five rooms.
     * The kickoff engines have refused it since the room-link work; a project
     * meeting had nothing stopping somebody pasting exactly that, and now that
     * the link is e-mailed out the cost of allowing it is five wasted diaries.
     */
    private function assertJoinableLink(?string $link): void
    {
        $link = trim((string) $link);
        if ($link === '') {
            return;
        }

        if (OnlineMeetingService::isInstant($link)) {
            throw new BusinessException(
                'That link starts a new, empty meeting for whoever opens it. '
                .'Start the meeting first, then paste the link of the room you are in.', 422);
        }
        if (! filter_var($link, FILTER_VALIDATE_URL) || parse_url($link, PHP_URL_SCHEME) !== 'https') {
            throw new BusinessException('Paste the full meeting link, starting with https://', 422);
        }
    }

    public function destroy(Request $request, int $project, int $meeting)
    {
        $this->guardManage($request, $project);
        $this->findMeeting($request->user()->tenant_id, $project, $meeting)->delete();

        return $this->success(null, 'Meeting deleted');
    }
}
