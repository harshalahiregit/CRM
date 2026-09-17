<?php

namespace Tests\Feature\Shared;

use Tests\TestCase;

/**
 * An un-onboarded vendor's workspace shows the next step, not forty empty screens.
 *
 * Both admin vendor workspaces opened with the same forty-odd sections whoever
 * the vendor was. On a vendor part-way through onboarding that is forty
 * sections of nothing — Purchase Orders for a company no order can be raised
 * against, Gate Log for workers nobody has registered, Renewal for a contract
 * that does not exist. Each one loads, queries and shows an empty list, and the
 * person looking cannot tell "nothing here yet" from "this is broken", nor find
 * the four entries that are the actual next step.
 *
 * The rule lives in one module because these two workspaces have drifted apart
 * over vocabulary alone often enough that vendorDetailNav.jsx opens with a
 * warning about it. Two copies of this rule would be two answers to "is this
 * vendor ready", and the wrong one would be discovered by an admin staring at
 * an empty Purchase Order list.
 *
 * The lock is presentational, deliberately. Routes are untouched, so a
 * bookmarked URL still resolves — it governs what the screen puts in front of
 * somebody, not who is allowed where, and an admin who wants a locked section
 * is one approval away from it.
 */
class VendorWorkspaceLocksUntilOnboardedTest extends TestCase
{
    private function src(string $relative): string
    {
        $path = base_path('../frontend/src/'.$relative);
        $this->assertFileExists($path, "{$relative} has moved — this guard needs repointing");

        return (string) file_get_contents($path);
    }

    /** One rule, in one place. */
    public function test_the_rule_is_defined_once(): void
    {
        $lock = $this->src('lib/vendors/workspaceLock.js');

        foreach (['isWorkspaceUnlocked', 'lockNav', 'lockNotice', 'PRE_ONBOARDING_SECTIONS'] as $symbol) {
            $this->assertTrue(
                str_contains($lock, "export const {$symbol}") || str_contains($lock, "export function {$symbol}"),
                "workspaceLock no longer exports {$symbol}",
            );
        }
    }

    /**
     * The four sections that survive the lock are the ones the decision needs.
     *
     * Overview carries the approve/hold/reject panel and the progress; Profile
     * says whether the company is who they claim; Contact says who to talk to;
     * Documents is what they actually uploaded. Remove any of these and an
     * admin cannot complete the step the lock exists to point them at.
     */
    public function test_the_unlocked_sections_are_the_ones_the_next_step_needs(): void
    {
        $lock = $this->src('lib/vendors/workspaceLock.js');

        foreach (['overview', 'profile', 'documents'] as $section) {
            $this->assertMatchesRegularExpression("/'{$section}'/", $lock,
                "'{$section}' is no longer offered before onboarding completes — without it the "
                .'admin cannot do the one thing the locked workspace is asking them to do');
        }

        // Both spellings, because the two workspaces key their tabs differently:
        // Purchase by URL segment ('contacts'), TPV by slugged label ('contact').
        $this->assertStringContainsString("'contact'", $lock);
        $this->assertStringContainsString("'contacts'", $lock);
    }

    /** And both workspaces read it, rather than each deciding for itself. */
    public function test_both_admin_workspaces_use_that_one_rule(): void
    {
        foreach ([
            'modules/purchase/pages/vendor-detail/PurchaseVendorDetailLayout.jsx',
            'modules/tpv/pages/TpvVendorDetail.jsx',
        ] as $page) {
            $src = $this->src($page);

            $this->assertStringContainsString("from '@/lib/vendors/workspaceLock'", $src,
                "{$page} no longer imports the shared lock — a second copy of 'is this vendor ready' "
                .'is how these two workspaces drift');

            $this->assertStringContainsString('isWorkspaceUnlocked', $src);
            $this->assertStringContainsString('lockNav', $src);

            $this->assertStringContainsString('lockedNotice', $src,
                "{$page} hides the locked sections without saying so. A workspace that silently "
                .'drops forty entries is the same confusion as one that shows forty empty screens.');
        }
    }

    /**
     * The vendor's own portal follows the same rule from the other side.
     *
     * A vendor mid-onboarding was shown the whole portal — My Items, Purchase
     * Orders, Debit Notes, Payments, PTW, Packages, Shipping. None of it can
     * contain anything: there is no order to a company not approved yet. So a
     * vendor's first sight of the system was thirty empty screens around the two
     * they were meant to use.
     *
     * Dashboard and Onboarding survive, and only those two. Profile and
     * Documents are steps 2 and 3 INSIDE the wizard — listing them beside it
     * would offer two doors into one room, which is why the portal's set is
     * deliberately not the admin's.
     */
    public function test_the_vendor_portal_shows_only_the_next_step_too(): void
    {
        $lock = $this->src('lib/vendors/workspaceLock.js');

        $this->assertStringContainsString('PRE_ONBOARDING_PORTAL_SECTIONS', $lock);
        $this->assertMatchesRegularExpression(
            "/PRE_ONBOARDING_PORTAL_SECTIONS\s*=\s*\['dashboard',\s*'onboarding'\]/",
            $lock,
            "the portal's pre-onboarding set changed. Widening it puts empty screens back in front of "
            .'a vendor who has not been approved; narrowing it hides the wizard they are being asked '
            .'to complete.',
        );

        // One registry drives BOTH portals, so the gate belongs there and
        // nowhere else — a per-portal copy is how these two last drifted.
        $sections = $this->src('pages/vendor-portal/portalSections.js');

        $this->assertStringContainsString("from '@/lib/vendors/workspaceLock'", $sections);
        $this->assertStringContainsString('isPortalSectionUnlocked', $sections,
            'portalSections no longer gates the tree, so an un-onboarded vendor sees every section again');
    }

    /**
     * The lock never touches routing.
     *
     * If it did, a bookmark or a link from elsewhere in the app would 404 for a
     * vendor mid-onboarding, which is a different and much worse behaviour than
     * a tidier sidebar.
     */
    public function test_the_lock_does_not_gate_the_routes(): void
    {
        $purchase = $this->src('modules/purchase/pages/vendor-detail/PurchaseVendorDetailLayout.jsx');

        $this->assertStringContainsString('BUILT_NAV_ITEMS.map((it) => (', $purchase,
            'the route table is no longer built from the full item list — locking the routes would '
            .'turn a presentational rule into a 404 on a bookmarked link');
    }
}
