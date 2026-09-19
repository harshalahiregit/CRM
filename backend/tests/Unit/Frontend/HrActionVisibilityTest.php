<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Four screens that offered actions the server refuses.
 *
 * Each of these is a button whose endpoint is already gated — the backend was
 * never wrong. What was wrong is that the screen did not ask, so the workflow
 * read as "fill this in, press save, be told no".
 *
 * Source-level because the frontend has no test runner, the same approach as
 * BannedPatternsTest and StaffPermissionModuleParityTest beside this file. The
 * capability these read — canManageHrQueue(user) from modules/hr/constants —
 * is answered by the server and covered behaviourally by
 * FrontendCapabilityPayloadTest.
 */
class HrActionVisibilityTest extends TestCase
{
    private const SRC = __DIR__.'/../../../../frontend/src';

    private function read(string $path): string
    {
        $full = self::SRC.'/'.$path;
        $this->assertFileExists($full, "{$path} has moved — update this test to follow it.");

        return file_get_contents($full);
    }

    /* ── 1. Loans ─────────────────────────────────────────────────────── */

    /**
     * Every write on LoanManagement goes through LoanController, where all eight
     * methods call assertCanManage(). Create, edit, delete a type, and the whole
     * Draft → Submitted → Approved → Disbursed ladder.
     */
    public function test_loan_management_gates_its_writes(): void
    {
        $src = $this->read('modules/hr/components/operations/LoanManagement.jsx');

        $this->assertStringContainsString('canManageHrQueue', $src,
            'Loans must ask the same question LoanController asks.');

        // The creation entry point.
        $this->assertStringContainsString("view !== 'recovery' && canManageHr &&", $src,
            'The Add Loan Type / New Loan button opens a modal that cannot save.');

        // The decision ladder, passed down and guarded as one block.
        $this->assertStringContainsString('canManageHr={canManageHr}', $src,
            'LoanDetail holds submit/cancel/approve/reject/disburse/close and needs the answer.');
        $this->assertStringContainsString('function LoanDetail({ loan, onClose, act, showToast, canManageHr })', $src);

        // Writing off money owed is the most consequential button here.
        $this->assertStringContainsString("canManageHr && i.status === 'Pending'", $src,
            'Waiving an instalment is assertCanManage\'d too.');
    }

    /* ── 2. Interview evaluation ──────────────────────────────────────── */

    /**
     * The panel already received `manageHr` and used it for attaching and
     * detaching questions. Scoring was left on readOnly alone, so somebody who
     * could not save was still shown a Save button the moment they typed a score.
     */
    public function test_interview_evaluation_is_gated_on_the_existing_prop(): void
    {
        $src = $this->read('modules/hr/components/InterviewQuestionPanel.jsx');

        $this->assertStringContainsString('manageHr && !readOnly && Object.keys(dirty).length > 0', $src,
            'Save Evaluation must require manageHr, not just !readOnly.');

        // No second source of truth invented for a prop the component already
        // had. Checked as an IMPORT, not as a substring — the docblock names the
        // backend method it mirrors, and that is worth keeping.
        $this->assertDoesNotMatchRegularExpression('/^import .*canManageHrQueue/m', $src,
            'The panel is given manageHr by its parent; it must not fetch its own answer.');
    }

    /** read-only still closes the panel for everybody, HR included. */
    public function test_interview_evaluation_still_respects_read_only(): void
    {
        $src = $this->read('modules/hr/components/InterviewQuestionPanel.jsx');

        $this->assertStringContainsString('!readOnly', $src,
            'A cancelled round is closed to everyone — that behaviour predates this and stays.');
    }

    /* ── 3. External job id ───────────────────────────────────────────── */

    public function test_external_posting_card_gates_its_save(): void
    {
        $src = $this->read('components/hr/ExternalPostingCard.jsx');

        $this->assertStringContainsString('canManageHrQueue', $src,
            'updateExternalId() is authorize($user->canManageHrQueue())\'d in JobPostingService.');
        $this->assertStringContainsString('disabled={saving || !canManageHr}', $src);

        // Recorded in the file and here: this component is currently rendered
        // nowhere. The gate is so the mismatch cannot ship if it is wired up.
        $this->assertStringContainsString('not currently rendered anywhere', $src,
            'The comment keeping that fact honest must stay with the code.');
    }

    /* ── 4. The HR dashboard nav item ─────────────────────────────────── */

    /**
     * /api/hr/dashboard is permission:hr_attendance,view_global — the same grid
     * module `managesHr` already reads, so this is an exact mirror rather than a
     * guess. It was the last HR nav item still offering a locked door; the
     * Attendance and Requests groups have been gated on it since they were added.
     */
    public function test_the_hr_dashboard_nav_item_is_gated(): void
    {
        $src = $this->read('components/layout/Sidebar.jsx');

        $this->assertStringContainsString('{managesHr && <NavLeaf item={HR_DASHBOARD} />}', $src);
        $this->assertStringContainsString("const managesHr = canSee('hr_attendance')", $src,
            'And it must keep reading the module the route is actually gated on.');
    }

    /* ── the rule that holds all four together ────────────────────────── */

    /**
     * None of these may reintroduce a hardcoded role name. That is what went
     * stale in constants.js and broke configurable roles; a screen doing it
     * locally would break them the same way and be harder to find.
     */
    public function test_no_screen_hardcodes_a_role_name(): void
    {
        $files = [
            'modules/hr/components/operations/LoanManagement.jsx',
            'modules/hr/components/InterviewQuestionPanel.jsx',
            'components/hr/ExternalPostingCard.jsx',
        ];

        foreach ($files as $file) {
            $src = $this->read($file);

            foreach (['hr_executive', 'hr_recruiter', 'hr_manager', 'department_head', 'internal_role'] as $literal) {
                $this->assertStringNotContainsString($literal, $src,
                    "{$file} must not decide authority from a role string — a custom role would be missed.");
            }
        }
    }
}
