<?php

namespace App\Http\Controllers\Api\Project;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Project\Project;
use App\Models\Shared\PartyAssignee;
use App\Services\Shared\PartyAssignmentService;
use App\Support\Party\PartyType;
use Illuminate\Http\Request;

/**
 * The people at a client, a vendor or a TPV who are on this PROJECT.
 *
 * The project's Vendors tab could only ever DERIVE who was involved, by reading
 * the assignees off the project's tasks. So a vendor engaged for the project as
 * a whole — before a single task exists, which is the normal order — appeared
 * nowhere, and the tab read "no vendors are assigned to this project's tasks
 * yet" on a project with three vendors on site.
 *
 * Same engine as the task side, deliberately: PartyAssignmentService, one table,
 * one set of rules about who may be assigned. The only thing that differs is the
 * subject the row hangs off.
 *
 * The picker endpoints are NOT duplicated here — the browser calls
 * /api/tasks/parties/* for the directory, because the list of assignable people
 * is the same list. Only the write is project-specific.
 */
class ProjectPartyController extends Controller
{
    use ApiResponse;

    public function __construct(private PartyAssignmentService $parties)
    {
    }

    /** Everyone currently on this project. */
    public function index(Request $request, int $project)
    {
        $model = $this->find($request, $project);

        return $this->success(
            $this->parties->forSubject(PartyAssignee::SUBJECT_PROJECT, $model->id),
            'Project vendors retrieved'
        );
    }

    /**
     * Set them. The payload is the list to END UP with, the same contract as the
     * task endpoint — an empty array clears them.
     */
    public function sync(Request $request, int $project)
    {
        $data = $request->validate([
            'parties'              => ['present', 'array'],
            'parties.*.party_type' => ['required', 'string'],
            'parties.*.party_id'   => ['required', 'integer', 'min:1'],
        ]);

        $model = $this->find($request, $project);

        $rows = $this->parties->sync(
            PartyAssignee::SUBJECT_PROJECT,
            $model->id,
            $data['parties'],
            $request->user()->tenant_id,
            $request->user()->id,
        );

        return $this->success($rows->map(fn ($r) => $r->toChip())->values(), 'Project vendors updated');
    }

    /** The kinds of team that can be put on a project. */
    public function kinds()
    {
        $kinds = [];
        foreach (PartyType::ORG_TO_PARTY as $orgType => $partyType) {
            $kinds[] = [
                'org_type'    => $orgType,
                'label'       => PartyType::orgLabel($orgType),
                'party_label' => PartyType::label($partyType),
            ];
        }

        return $this->success($kinds, 'Assignable teams retrieved');
    }

    /**
     * Tenant-scoped lookup.
     *
     * A 404 for a project in another tenant, not a 403: confirming the id is
     * real is how an id range gets walked.
     */
    private function find(Request $request, int $project): Project
    {
        return Project::forTenant($request->user()->tenant_id)->find($project)
            ?? abort(404, 'That project was not found.');
    }
}
