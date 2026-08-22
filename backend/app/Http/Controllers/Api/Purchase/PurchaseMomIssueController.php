<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomIssue;
use App\Services\Purchase\PurchaseMomIssueService;
use App\Support\Purchase\PurchaseMomIssueStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase MOM issues — the staff surface over the Purchase-owned issue register
 * (PurchaseMomIssueService, purchase_mom_issues). Independent of the shared/TPV
 * meeting_issues. Every meeting and issue is tenant-guarded (404 on mismatch),
 * and each issue must belong to the bound meeting.
 */
class PurchaseMomIssueController extends Controller
{
    public function __construct(private PurchaseMomIssueService $service)
    {
    }

    public function index(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertMeeting($request, $kickoff);

        return response()->json($this->service->forMeeting($kickoff));
    }

    public function store(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $this->assertMeeting($request, $kickoff);

        $data = $request->validate([
            'title'                => 'required|string|max:255',
            'description'          => 'nullable|string|max:2000',
            'category'             => ['nullable', Rule::in(PurchaseMomIssueStatus::CATEGORIES)],
            'severity'             => ['nullable', Rule::in(PurchaseMomIssueStatus::SEVERITIES)],
            'owner_participant_id' => 'nullable|integer',
            'owner_names'          => 'nullable|string|max:300',
            'due_date'             => 'nullable|date',
        ]);

        return response()->json($this->service->create($kickoff, $data, $request->user()), 201);
    }

    public function update(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomIssue $issue)
    {
        $this->assertIssue($request, $kickoff, $issue);

        $data = $request->validate([
            'title'                => 'sometimes|string|max:255',
            'description'          => 'sometimes|nullable|string|max:2000',
            'category'             => ['sometimes', 'nullable', Rule::in(PurchaseMomIssueStatus::CATEGORIES)],
            'severity'             => ['sometimes', 'nullable', Rule::in(PurchaseMomIssueStatus::SEVERITIES)],
            'owner_participant_id' => 'sometimes|nullable|integer',
            'owner_names'          => 'sometimes|nullable|string|max:300',
            'due_date'             => 'sometimes|nullable|date',
        ]);

        return response()->json($this->service->update($issue, $data, $request->user()));
    }

    public function progress(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomIssue $issue)
    {
        $this->assertIssue($request, $kickoff, $issue);

        $data = $request->validate([
            'status' => ['required', Rule::in(PurchaseMomIssueStatus::ALL)],
        ]);

        return response()->json($this->service->progress($issue, $data['status'], $request->user()));
    }

    public function convert(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomIssue $issue)
    {
        $this->assertIssue($request, $kickoff, $issue);

        $data = $request->validate([
            'target' => ['required', Rule::in(['ncr', 'capa', 'approval'])],
        ]);

        $result = match ($data['target']) {
            'ncr'      => $this->service->convertToNcr($issue, $request->user()),
            'approval' => $this->service->convertToApproval($issue, $request->user()),
            default    => $this->service->convertToCapa($issue, $request->user()),
        };

        return response()->json($result);
    }

    public function destroy(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomIssue $issue)
    {
        $this->assertIssue($request, $kickoff, $issue);

        $this->service->delete($issue, $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    private function assertMeeting(Request $request, PurchaseKickoffMeeting $kickoff): void
    {
        abort_unless((int) $kickoff->tenant_id === (int) $request->user()->tenant_id, 404, 'Meeting not found');
    }

    private function assertIssue(Request $request, PurchaseKickoffMeeting $kickoff, PurchaseMomIssue $issue): void
    {
        $this->assertMeeting($request, $kickoff);
        abort_unless(
            (int) $issue->tenant_id === (int) $request->user()->tenant_id
                && (int) $issue->purchase_kickoff_meeting_id === (int) $kickoff->id,
            404,
            'Issue not found'
        );
    }
}
