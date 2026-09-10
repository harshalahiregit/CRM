<?php

namespace Sire\Http\Controllers;

use Sire\Contracts\SireNotesProvider;
use Sire\Dto\SireNote;
use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Models\Report;
use Sire\Services\SireAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SIRE — user comments, through the CRM's notes system.
 *
 * No sire_comments table. Comments reach whatever SireNotesProvider is bound to,
 * which already handles sanitisation, mentions and visibility the way the rest
 * of the product does.
 *
 * The subject is always the ROUTE-BOUND report, never a value from the payload,
 * so a comment cannot be retargeted at another record by editing a request.
 *
 * Comments are the one part of a SIRE timeline that can be edited. System
 * events cannot — see SireAuditProvider, which has no update and no delete.
 */
class SireCommentController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(
        private readonly SireNotesProvider $notes,
        private readonly SireAccessService $access,
    ) {
    }

    public function index(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success(array_map(
            fn (SireNote $note) => $note->toArray(),
            $this->notes->listFor($report, $this->sireUser()),
        ));
    }

    public function store(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        // internal: true. An engineering issue discusses defects in a customer's
        // data; nothing here is customer-visible unless somebody says so.
        $note = $this->notes->add($report, $data['body'], $this->sireUser(), true);

        return $this->success($note->toArray(), 201);
    }

    public function update(Request $request, Report $report, int $note): JsonResponse
    {
        $this->assertTenantOwnership($report);
        $existing = $this->assertCommentBelongsToReport($note, $report);

        $user = $this->sireUser();

        if ($existing->author?->is($user) !== true
            && ! $this->access->can($user, 'sire.comment.moderate', $report)) {
            abort(403, 'You can only edit your own comments.');
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        return $this->success($this->notes->update($note, $data['body'], $user)->toArray());
    }

    public function destroy(Request $request, Report $report, int $note): JsonResponse
    {
        $this->assertTenantOwnership($report);
        $existing = $this->assertCommentBelongsToReport($note, $report);

        $user = $this->sireUser();

        if ($existing->author?->is($user) !== true
            && ! $this->access->can($user, 'sire.comment.moderate', $report)) {
            abort(403, 'You can only delete your own comments.');
        }

        $this->notes->delete($note, $user);

        return $this->success(null, 204);
    }

    /**
     * A note id in a URL is just a number, and note ids are a global sequence.
     * Without this check, /sire/reports/1/comments/999 would happily edit a note
     * attached to an invoice in another tenant.
     *
     * 404 rather than 403, for the same reason as everywhere else in SIRE: 403
     * would confirm the note exists.
     *
     * All three conditions are checked, not just the id: subject type, subject id
     * AND tenant. Any one of them alone leaves a gap — same id on a different
     * subject type, or the right subject in the wrong tenant.
     */
    private function assertCommentBelongsToReport(int $noteId, Report $report): SireNote
    {
        $note = $this->notes->find($noteId);

        abort_if(
            $note === null
                || $note->subjectType !== Report::class
                || $note->subjectId !== (int) $report->id
                || $note->tenantId !== (int) $report->tenant_id,
            404,
        );

        return $note;
    }
}
