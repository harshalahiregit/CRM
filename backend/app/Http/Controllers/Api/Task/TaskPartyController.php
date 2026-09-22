<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Shared\PartyAssignmentService;
use App\Services\Task\TaskService;
use App\Models\Shared\PartyAssignee;
use App\Support\Party\PartyType;
use Illuminate\Http\Request;

/**
 * Assigning a task to a named person at a client, a vendor or a TPV.
 *
 * Three endpoints, in the order the picker walks them:
 *   GET  /tasks/parties/kinds              — Client / Vendor / TPV
 *   GET  /tasks/parties/{orgType}          — the companies of that kind
 *   GET  /tasks/parties/{orgType}/{org}    — the people inside one of them
 *   POST /tasks/{task}/party-assignees     — set the list
 *
 * Two stages rather than one flat list of every contact in the tenant, because
 * a flat list is thousands of rows in which two people called R. Kumar at two
 * different suppliers are indistinguishable. Choosing the company first is also
 * how somebody actually thinks about it: the work is for Southgate, and then it
 * is for whoever at Southgate does this.
 */
class TaskPartyController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PartyAssignmentService $parties,
        private TaskService $tasks,
    ) {
    }

    /** The kinds of team a task can be assigned into. */
    public function kinds()
    {
        $kinds = [];
        foreach (PartyType::ORG_TO_PARTY as $orgType => $partyType) {
            $kinds[] = [
                'org_type' => $orgType,
                'label'    => PartyType::orgLabel($orgType),
                'party_label' => PartyType::label($partyType),
            ];
        }

        return $this->success($kinds, 'Assignable teams retrieved');
    }

    /** The companies of one kind. */
    public function organisations(Request $request, string $orgType)
    {
        return $this->success(
            $this->parties->organisations($orgType, $request->user()->tenant_id, $request->query('search')),
            'Teams retrieved'
        );
    }

    /** The people inside one company. */
    public function contacts(Request $request, string $orgType, int $org)
    {
        return $this->success(
            $this->parties->contacts($orgType, $org, $request->user()->tenant_id, $request->query('search')),
            'People retrieved'
        );
    }

    /**
     * Set the party assignees on a task. The payload is the list to END UP with,
     * the same contract as /assignees — an empty array clears them.
     */
    public function sync(Request $request, int $task)
    {
        $data = $request->validate([
            'parties'              => ['present', 'array'],
            'parties.*.party_type' => ['required', 'string'],
            'parties.*.party_id'   => ['required', 'integer', 'min:1'],
        ]);

        $tenantId = $request->user()->tenant_id;

        // The same gate every other single-task endpoint uses, so a task you
        // cannot open is not a task you can reassign.
        $model = $this->tasks->assertTaskVisible(
            $task, $tenantId, $request->user()->id, $request->user()->role === 'admin'
        );

        $rows = $this->parties->sync(
            PartyAssignee::SUBJECT_TASK, $model->id, $data['parties'], $tenantId, $request->user()->id
        );

        return $this->success(
            $rows->map(fn ($r) => $r->toChip())->values(),
            'Assignees updated'
        );
    }
}
