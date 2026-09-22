<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrPoshCase;
use App\Services\Hr\Posh\PoshAccessResolver;
use App\Services\Hr\Posh\PoshCaseReadAuditor;
use Illuminate\Http\Request;

/**
 * Reading a POSH case, for somebody who is on it.
 *
 * TWO ENDPOINTS, AND NO LIST. There is deliberately no index: a list is an
 * enumeration surface, and on this data the existence of a case is itself a
 * disclosure. Everything here is reached by an id the caller already has, and
 * an id they should not have returns the same 404 as one that does not exist.
 *
 * NO PERMISSION CHECK IN THIS CONTROLLER, and that is not an omission. Every
 * other HR controller opens with a capability check; here the only question is
 * whether this person is on this case, and PoshAccessResolver answers it. A
 * permission check beside it would imply a second way in and eventually become
 * one.
 *
 * The payload is assembled by hand rather than returned as a model, following
 * the public contract surface: a model would carry whatever column is added
 * next — a retention policy, an anonymisation stamp, a future token — to a
 * reader who was never meant to see it.
 *
 * What this phase deliberately does not expose: deliberations, notes,
 * evidence, findings, dates, inquiry rounds, complainant contact details and
 * anything token-shaped. None of it exists yet, and the shape of this response
 * is not a promise about what the case workspace will show.
 */
class PoshCaseController extends Controller
{
    public function __construct(
        private PoshAccessResolver $access,
        private PoshCaseReadAuditor $reads,
    ) {
    }

    /** One case, for a member of it. */
    public function show(Request $request, int $id)
    {
        $case = $this->access->find($this->tenant($request), $id, $request->user());

        // After authorisation, never before: a read recorded first would claim
        // something the line above might have refused.
        $this->reads->record($case, $request->user(), PoshCaseReadAuditor::SURFACE_SHOW, $request->ip());

        return response()->json(['data' => $this->payload($case)]);
    }

    /** Who else is on this case. Members only, like everything else here. */
    public function members(Request $request, int $id)
    {
        $case = $this->access->find($this->tenant($request), $id, $request->user());

        $this->reads->record($case, $request->user(), PoshCaseReadAuditor::SURFACE_MEMBERS, $request->ip());

        // The CURRENT roster only. Ended periods are history for the audit
        // trail, not something a member needs read back to them, and listing
        // who used to have access would answer a question nobody asked.
        $members = $case->activeMembers()->with('user:id,name,email')->get();

        return response()->json([
            'data' => $members->map(fn ($m) => [
                'user_id'  => (int) $m->user_id,
                'name'     => $m->user?->name,
                'email'    => $m->user?->email,
                'role_key' => $m->role_key,
                'added_at' => optional($m->added_at)->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /* ── internals ────────────────────────────────────────────────────── */

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    /**
     * The case as a member sees it in this phase.
     *
     * Narrow on purpose. The respondent's identity is included because a
     * committee deciding a complaint has to know who it is about; the
     * complainant's is included for the same reason. Neither of them can read
     * this response — they are not members.
     */
    private function payload(HrPoshCase $case): array
    {
        return [
            'id'                => $case->id,
            'reference'         => $case->reference,
            'status'            => $case->status,
            'committee_id'      => (int) $case->committee_id,
            'complainant_type'  => $case->complainant_type,
            'complainant_label' => $case->complainant_label,
            'respondent_label'  => $case->respondent_label,
            'incident_at'       => optional($case->incident_at)->toIso8601String(),
            'incident_place'    => $case->incident_place,
            'narrative'         => $case->narrative,
            'created_at'        => optional($case->created_at)->toIso8601String(),
        ];
    }
}
