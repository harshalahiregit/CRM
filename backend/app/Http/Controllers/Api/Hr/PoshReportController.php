<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Hr\Posh\PoshAggregateReportService;
use App\Support\Hr\StaffPermission;
use Illuminate\Http\Request;

/**
 * POSH statistics. Never POSH cases.
 *
 * A DIFFERENT QUESTION FROM CASE ACCESS, and the separation is the whole
 * reason this controller exists rather than another method on the case
 * controller. "How many complaints were upheld last quarter" is a question a
 * board or a compliance officer may legitimately ask without being entitled to
 * read a single file. So hr_posh_reports is gated the ordinary way — through
 * StaffPermissionService, administrator bypass and all — because nothing it
 * returns is case content.
 *
 * AND IT GRANTS NOTHING ELSE. hr_posh_reports is never consulted by
 * PoshAccessResolver, PoshCaseAuthority or any case-content service; a test
 * greps those files to prove the string does not appear in them. Somebody who
 * holds it and asks for a case still gets the same 404 as a stranger.
 *
 * The service decides what may be published. This controller does not filter,
 * reshape or add to its output — a second place deciding what is safe is a
 * second place to get it wrong.
 */
class PoshReportController extends Controller
{
    public function __construct(private PoshAggregateReportService $reports)
    {
    }

    public function summary(Request $request)
    {
        $this->gate($request);

        $filters = $request->validate([
            'from'   => 'nullable|date',
            'to'     => 'nullable|date',
            'bucket' => 'nullable|in:'.implode(',', PoshAggregateReportService::BUCKETS),
        ]);

        // Who looked at the numbers is ordinary audit material — unlike a case
        // read, which is deliberately kept out of audit_logs and off every
        // audit browser in the product.
        AuditLog::create([
            'tenant_id'      => $this->tenant($request),
            'auditable_type' => 'PoshReport',
            'auditable_id'   => 0,
            'action'         => 'POSH Aggregate Report Viewed',
            'actor_id'       => $request->user()?->id,
            'actor_name'     => $request->user()?->name,
            'actor_role'     => $request->user()?->internal_role ?: $request->user()?->role,
            'metadata'       => ['bucket' => $filters['bucket'] ?? null],
        ]);

        return response()->json([
            'data' => $this->reports->summary($this->tenant($request), $filters),
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    /**
     * The ordinary capability check, bypass included.
     *
     * Deliberately NOT the hand-rolled grid read that hr_posh_intake uses.
     * That one exists because raising a complaint must not be implied by being
     * an administrator — an administrator is often who a complaint is about.
     * Aggregate counts carry no such risk, so the normal behaviour is right
     * and the difference between the two is stated rather than left to be
     * noticed.
     */
    private function gate(Request $request): void
    {
        $allowed = app(\App\Services\Auth\StaffPermissionService::class)->can(
            $request->user(), StaffPermission::VIEW_GLOBAL, 'hr_posh_reports'
        );

        abort_unless($allowed, 403, 'You are not authorised to view POSH reports');
    }
}
