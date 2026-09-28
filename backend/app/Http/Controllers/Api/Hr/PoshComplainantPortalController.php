<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseToken;
use App\Services\Hr\Posh\PoshCaseReadAuditor;
use App\Services\Hr\Posh\PoshComplainantView;
use App\Services\Hr\Posh\PoshTokenService;
use Illuminate\Http\Request;

/**
 * The complainant's view of their own case.
 *
 * UNAUTHENTICATED AND OUTSIDE EVERY HR GROUP. A complainant has no User
 * account and never gets one from this — they are not staff, and they are
 * emphatically not a case member. The link is the credential, which is why
 * these routes live in their own file with their own prefix: dropped into the
 * authenticated HR group they would inherit middleware that assumes a
 * request->user(), and there is none.
 *
 * READ ONLY. There is no POST. A complainant cannot write to the thread,
 * cannot upload, and cannot change anything about the case. Those need a
 * decision about what the committee is obliged to do with what arrives, and
 * that decision has not been made.
 *
 * ONE REFUSAL, ALWAYS THE SAME. Malformed, unknown, expired, revoked,
 * superseded, or belonging to a case that no longer exists — every one of them
 * produces the identical 404 with the identical body, from a single private
 * method. Saying "expired" would confirm the token was real and invite a hunt
 * for a fresher one; saying "revoked" would confirm somebody took it away.
 *
 * NO LISTING. The token addresses exactly one case and there is no index
 * route. A credential that could enumerate cases would be worse than no
 * credential at all.
 */
class PoshComplainantPortalController extends Controller
{
    public function __construct(
        private PoshTokenService $tokens,
        private PoshComplainantView $view,
        private PoshCaseReadAuditor $reads,
    ) {
    }

    /** GET /api/posh/portal/{token} */
    public function show(Request $request, string $token)
    {
        [$case, $row] = $this->resolve($token);

        $this->record($case, $row, $request, PoshCaseReadAuditor::SURFACE_PORTAL_SHOW);

        return response()->json(['data' => $this->view->case($case)]);
    }

    /** GET /api/posh/portal/{token}/thread */
    public function thread(Request $request, string $token)
    {
        [$case, $row] = $this->resolve($token);

        $this->record($case, $row, $request, PoshCaseReadAuditor::SURFACE_PORTAL_THREAD);

        return response()->json(['data' => $this->view->thread($case)]);
    }

    /* ── internals ────────────────────────────────────────────────────── */

    /**
     * The case behind the link, or the single refusal.
     *
     * @return array{0: HrPoshCase, 1: HrPoshCaseToken}
     */
    private function resolve(string $token): array
    {
        $resolved = $this->tokens->resolve($token);

        if (! $resolved) {
            $this->refuse();
        }

        return [$resolved['case'], $resolved['token']];
    }

    /**
     * The one answer.
     *
     * abort() rather than BusinessException so the body is fixed here and
     * cannot drift with an exception handler that formats differently for a
     * different reason. A test asserts every failure mode returns bytes
     * identical to this.
     */
    private function refuse(): never
    {
        abort(404, 'This link is not valid.');
    }

    /**
     * Record the read, after it has been authorised and never before.
     *
     * actor_id is null because there is no User — the column was made nullable
     * in 3b for exactly this caller. The label is the token's, which carries
     * the case reference and nothing sensitive. The raw token is not written,
     * and neither is its hash.
     */
    private function record(HrPoshCase $case, HrPoshCaseToken $row, Request $request, string $surface): void
    {
        $this->reads->recordAnonymous($case, $row->label, $surface, $request->ip());

        $this->tokens->touch($row);
    }
}
