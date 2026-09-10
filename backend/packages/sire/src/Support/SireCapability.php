<?php

namespace Sire\Support;

/**
 * SIRE SDK — the capability vocabulary. SIRE owns this list; the host maps to it.
 *
 * THE POINT OF A VOCABULARY
 *
 * SIRE never asks `$user->hasRole('crm-qa-lead')`, because it cannot know what a
 * host calls its roles. It asks `can($user, 'sire.qa.execute')`. The host decides
 * which of its roles grants that. SIRE's rules stay the same whether the host has
 * Spatie permissions, a role column, or a bespoke ACL.
 *
 * TWO GRANULARITIES, BECAUSE HOSTS DIFFER
 *
 * CANONICAL is the real vocabulary: 16 capabilities, each tied to specific
 * workflow transitions. A host with a real permission system maps these directly.
 *
 * COARSE is nine broad grants for hosts whose roles are blunter than that.
 * Granting a coarse name grants every canonical capability beneath it. Mapping
 * `sire.manage` to your administrator role is a complete, legitimate integration
 * — most hosts will start there and refine later, if ever.
 *
 * The two are not alternatives to choose between. COARSE is defined IN TERMS OF
 * CANONICAL, so they can be mixed freely and cannot drift apart.
 */
final class SireCapability
{
    // ---- reading ----------------------------------------------------------
    public const VIEW_OWN    = 'sire.report.view_own';
    public const VIEW_GLOBAL = 'sire.report.view_global';
    public const EXPORT      = 'sire.export';

    // ---- the issue lifecycle ----------------------------------------------
    public const CREATE  = 'sire.report.create';
    public const TRIAGE  = 'sire.report.triage';
    public const ASSIGN  = 'sire.report.assign';
    public const DEVELOP = 'sire.report.develop';
    public const CLOSE   = 'sire.report.close';
    public const REOPEN  = 'sire.report.reopen';

    // ---- quality assurance -------------------------------------------------
    public const QA_EXECUTE = 'sire.qa.execute';

    // ---- change control ----------------------------------------------------
    public const CHANGE_REVIEW  = 'sire.change.review';
    public const CHANGE_APPROVE = 'sire.change.approve';

    // ---- quality: root cause and corrective action -------------------------
    public const RCA_CONFIRM = 'sire.rca.confirm';
    public const CAPA_VERIFY = 'sire.capa.verify';
    public const KB_AUTHOR   = 'sire.kb.author';

    // ---- release governance ------------------------------------------------
    public const RELEASE_MANAGE  = 'sire.release.manage';
    public const RELEASE_APPROVE = 'sire.release.approve';
    public const RELEASE_NOTES_APPROVE = 'sire.release_notes.approve';
    public const RELEASE_NOTES_PUBLISH = 'sire.release_notes.publish';

    /**
     * Emergency override. Deliberately NOT in any coarse grant except
     * sire.manage, and deliberately not held by leads.
     *
     * Approving a release and overriding its gates are different acts. Approval
     * says the gates passed; an override says they did not and we are shipping
     * anyway. Rolling the second into the first is how "we override sometimes"
     * becomes "we override by default".
     */
    public const RELEASE_OVERRIDE = 'sire.release.override';

    // ---- administration ----------------------------------------------------
    public const COMMENT_MODERATE = 'sire.comment.moderate';
    public const MASTERS_MANAGE   = 'sire.masters.manage';

    /**
     * Every capability SIRE will ever ask about.
     *
     * `tests/capabilities.test.mjs` asserts that every capability named by a
     * workflow transition appears here. That test exists because three of them
     * once did not: the whole change-approval track and release approval were
     * silently admin-only, because an undeclared capability falls through to
     * "denied" and nothing complained.
     */
    public const CANONICAL = [
        self::VIEW_OWN, self::VIEW_GLOBAL, self::EXPORT,
        self::CREATE, self::TRIAGE, self::ASSIGN, self::DEVELOP, self::CLOSE, self::REOPEN,
        self::QA_EXECUTE,
        self::CHANGE_REVIEW, self::CHANGE_APPROVE,
        self::RCA_CONFIRM, self::CAPA_VERIFY, self::KB_AUTHOR,
        self::RELEASE_MANAGE, self::RELEASE_APPROVE,
        self::RELEASE_NOTES_APPROVE, self::RELEASE_NOTES_PUBLISH, self::RELEASE_OVERRIDE,
        self::COMMENT_MODERATE, self::MASTERS_MANAGE,
    ];

    /**
     * Coarse grants, for hosts whose roles are broader than SIRE's vocabulary.
     *
     * Each expands to canonical capabilities. `sire.manage` deliberately expands
     * to everything: it is the "this person administers SIRE" grant, and a host
     * that maps only that has a working, if blunt, installation.
     *
     * @var array<string, array<int, string>>
     */
    public const COARSE = [
        'sire.view'     => [self::VIEW_OWN, self::VIEW_GLOBAL, self::EXPORT],
        'sire.create'   => [self::CREATE, self::VIEW_OWN],
        'sire.update'   => [self::DEVELOP, self::REOPEN],
        'sire.assign'   => [self::TRIAGE, self::ASSIGN],
        'sire.qa'       => [self::QA_EXECUTE, self::VIEW_GLOBAL],
        'sire.approve'  => [self::CHANGE_REVIEW, self::CHANGE_APPROVE, self::RCA_CONFIRM, self::CAPA_VERIFY],
        // Note what is absent: RELEASE_OVERRIDE. Granting "this person handles
        // releases" must not silently grant "this person may bypass the gates".
        'sire.release'  => [
            self::RELEASE_MANAGE, self::RELEASE_APPROVE,
            self::RELEASE_NOTES_APPROVE, self::RELEASE_NOTES_PUBLISH,
        ],
        'sire.settings' => [self::MASTERS_MANAGE, self::KB_AUTHOR],
        'sire.manage'   => self::CANONICAL,
    ];

    /**
     * Expand a mixed set of coarse and canonical grants into canonical ones.
     *
     * A host adapter that has resolved a user's roles to, say,
     * ['sire.qa', 'sire.report.close'] passes them here and gets back the full
     * canonical set to answer can() against.
     *
     * Unknown names are DROPPED rather than passed through. An unrecognised
     * capability must never become a grant — fail closed, always.
     *
     * @param  array<int, string> $granted
     * @return array<int, string>
     */
    public static function expand(array $granted): array
    {
        $expanded = [];

        foreach ($granted as $name) {
            if (isset(self::COARSE[$name])) {
                $expanded = array_merge($expanded, self::COARSE[$name]);

                continue;
            }

            if (in_array($name, self::CANONICAL, true)) {
                $expanded[] = $name;
            }
        }

        return array_values(array_unique($expanded));
    }

    public static function isKnown(string $capability): bool
    {
        return in_array($capability, self::CANONICAL, true) || isset(self::COARSE[$capability]);
    }
}
