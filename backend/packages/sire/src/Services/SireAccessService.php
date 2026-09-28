<?php

namespace Sire\Services;

use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Contracts\SireAuthorizationProvider;
use Sire\Support\SireCapability;
use Sire\Support\SireLoginType;

/**
 * SIRE — the ONLY place authorization is decided.
 *
 * The CRM has no permission system: authorization is a `role` string plus one
 * middleware, and the PERMISSION_MODULES matrix in the staff admin UI is a
 * frontend mock that nothing persists or reads. SIRE must not become the fourth
 * ad-hoc scheme, so every check funnels through this class, named in the
 * `module.action` shape that mock already uses. When a real permission layer
 * lands, this file changes and nothing else does.
 *
 * ENGINEERING ROSTERS (decision D13). SIRE needs a developer/QA distinction the
 * CRM's role model does not have. Rather than invent a permissions table, the
 * rosters are three lists of user ids in tenant_settings, managed by an admin on
 * the SIRE settings screen:
 *
 *     sire.roles.leads       triage, assign, release, close
 *     sire.roles.developers  pick up and fix
 *     sire.roles.qa          run QA
 *
 * A roster is an assignment list, not a permission matrix — it says who is on the
 * team, not what a permission means. Being the assignee or the QA assignee also
 * grants the matching capability, so an issue handed to someone outside the
 * roster still works.
 */
class SireAccessService
{
    /**
     * The vocabulary is SIRE-owned and lives in the SDK, so a host can read the
     * complete list without opening a service.
     *
     * It used to be duplicated here, and drifted: three capabilities named by
     * workflow transitions -- sire.change.review, sire.change.approve and
     * sire.release.approve -- were never listed, so can() fell through to
     * "denied" and the entire change-approval track was silently admin-only.
     * Nothing complained, because a missing capability looks exactly like a
     * refused one. tests/capabilities.test.mjs now fails the build on that.
     */
    public const CAPABILITIES = SireCapability::CANONICAL;

    /**
     * Capabilities every lead has.
     *
     * Change review, change approval and release approval belong here: they are
     * engineering-management decisions, and restricting them to administrators
     * means an admin has to be fetched every time a change request moves.
     */
    private const LEAD = [
        SireCapability::TRIAGE, SireCapability::ASSIGN, SireCapability::CLOSE,
        SireCapability::REOPEN, SireCapability::RELEASE_MANAGE, SireCapability::COMMENT_MODERATE,
        SireCapability::DEVELOP, SireCapability::QA_EXECUTE, SireCapability::VIEW_GLOBAL,
        SireCapability::CHANGE_REVIEW, SireCapability::CHANGE_APPROVE, SireCapability::RELEASE_APPROVE,
        SireCapability::RCA_CONFIRM, SireCapability::CAPA_VERIFY, SireCapability::KB_AUTHOR,
        SireCapability::RELEASE_NOTES_APPROVE, SireCapability::RELEASE_NOTES_PUBLISH,
        // RELEASE_OVERRIDE is NOT here. See the note on the constant: approving a
        // release and bypassing its gates are different acts, and a lead holding
        // both by default is how an emergency override stops being an emergency.
    ];

    public function __construct(private readonly SireAuthorizationProvider $authorization)
    {
    }

    public function can(SireUserIdentity $user, string $capability, ?Report $report = null): bool
    {
        // ---- gate 1: which of the four login types is this? ----------------
        //
        // Customers and vendors never reach SIRE, whatever capability they are
        // asking about. A SIRE issue says what is broken, how badly and how far
        // the fix has got -- exactly what a customer should hear from an account
        // manager rather than read raw, and exactly what an outside supplier
        // should not see at all.
        //
        // Which host role means what is NOT hardcoded here. It comes from
        // config('sire.login_types'), which an administrator confirms during
        // installation, because SIRE cannot know whether "partner" means a
        // colleague or a competitor's supplier.
        $loginType = SireLoginType::forRole($user->role);

        if (! in_array($loginType, SireLoginType::ENGINEERING, true)) {
            // Includes the unmapped case: a role in no list is denied. The
            // failure mode of "unmapped means denied" is a colleague asking an
            // admin for access; of "unmapped means allowed" it is a customer
            // reading the defect backlog. Only one is recoverable.
            return false;
        }

        if ($loginType === SireLoginType::ADMIN) {
            return true;
        }

        // A CRM with a real permission system gets the first word. If it has been
        // told about SIRE's capabilities it can grant them directly, and the
        // rules below become a fallback rather than the whole story.
        if ($this->authorization->can($user, $capability, $report)) {
            return true;
        }

        if (in_array($capability, ['sire.report.create', 'sire.report.view_own'], true)) {
            return true;
        }

        if ($this->onRoster($user, 'leads') && in_array($capability, self::LEAD, true)) {
            return true;
        }

        return match ($capability) {
            'sire.report.develop' => $this->onRoster($user, 'developers')
                || ($report && (int) $report->assignee_id === (int) $user->id),

            'sire.qa.execute' => $this->onRoster($user, 'qa')
                || ($report && (int) $report->qa_assignee_id === (int) $user->id),

            'sire.report.view_global' => $this->onRoster($user, 'developers') || $this->onRoster($user, 'qa'),

            // Emergency override. Not a lead capability and not in any coarse
            // grant but sire.manage: a tenant nominates override authority
            // explicitly, or it stays with administrators. Every use is audited
            // and shows on the release board.
            SireCapability::RELEASE_OVERRIDE => $this->onRoster($user, 'release_managers'),

            default => false,
        };
    }

    /**
     * 403, never 401. The SPA signs the user out on an auth-shaped 401, and
     * PermissionFailureStatusTest already guards that distinction elsewhere —
     * a permission failure must not log someone out.
     */
    public function assert(SireUserIdentity $user, string $capability, ?Report $report = null): void
    {
        abort_unless(
            $this->can($user, $capability, $report),
            403,
            'You do not have permission to do that on this issue.',
        );
    }

    /** Visibility scope for a user without view_global: own, assigned, or QA'd. */
    public function scopeVisible($query, SireUserIdentity $user)
    {
        if ($this->can($user, 'sire.report.view_global')) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('reporter_id', $user->id)
                ->orWhere('assignee_id', $user->id)
                ->orWhere('qa_assignee_id', $user->id);
        });
    }

    private function onRoster(SireUserIdentity $user, string $roster): bool
    {
        return in_array(
            (int) $user->id,
            $this->authorization->roster((int) $user->tenantId, $roster),
            true,
        );
    }
}
