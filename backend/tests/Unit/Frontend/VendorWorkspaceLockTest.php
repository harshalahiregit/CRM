<?php

namespace Tests\Unit\Frontend;

use Tests\TestCase;

/**
 * A locked vendor section must refuse to render, not merely be hidden.
 *
 * The workspace lock has always removed locked entries from the sidebar, and
 * the module that does it said out loud what that left behind:
 *
 *   "Everything below is still routed and still reachable by URL; the lock
 *    decides what the sidebar offers, not what exists."
 *
 * That is not a lock, it is a tidy menu. The section still opened from a
 * bookmark, from a pasted link, and from the module's own registers — Purchase
 * → Prequalification lists every vendor, and picking one walked straight into a
 * workspace that was meant to be shut. Purchase reaches its sections by route
 * and TPV by a `?tab=` query string, so both were one hand-typed URL away.
 *
 * These assertions are about the SHAPE of the guard rather than its output,
 * because the failure mode is somebody deleting it while the sidebar keeps
 * working — at which point every screen still looks right and the hole is back.
 */
class VendorWorkspaceLockTest extends TestCase
{
    private const SRC = __DIR__.'/../../../../frontend/src';

    private function read(string $path): string
    {
        $full = self::SRC.'/'.$path;
        $this->assertFileExists($full, "{$path} has moved — update this test to follow it.");

        return file_get_contents($full);
    }

    /** @return array<string, string> */
    public static function workspaces(): array
    {
        return [
            'Purchase' => ['modules/purchase/pages/vendor-detail/PurchaseVendorDetailLayout.jsx'],
            'TPV'      => ['modules/tpv/pages/TpvVendorDetail.jsx'],
        ];
    }

    /**
     * @dataProvider workspaces
     */
    public function test_each_workspace_guards_the_section_itself(string $page): void
    {
        $src = $this->read($page);

        $this->assertStringContainsString(
            'isSectionUnlocked',
            $src,
            'This workspace renders its sections without asking whether they are '
            .'unlocked. Hiding the sidebar entry is not enough — the section is '
            .'still reachable by URL.'
        );

        $this->assertStringContainsString(
            'LockedSection',
            $src,
            'Nothing is rendered in place of a locked section, so the guard has '
            .'no answer to give.'
        );
    }

    /**
     * @dataProvider workspaces
     */
    public function test_each_workspace_still_narrows_the_sidebar(string $page): void
    {
        // Both halves matter. The guard stops the section opening; lockNav is
        // what stops forty dead entries being offered in the first place.
        $this->assertStringContainsString('lockNav', $this->read($page));
    }

    /** The rule itself lives in one module, so the two cannot drift apart. */
    public function test_one_lock_module_serves_both_workspaces(): void
    {
        foreach (self::workspaces() as $name => [$page]) {
            $this->assertStringContainsString(
                "from '@/lib/vendors/workspaceLock'",
                $this->read($page),
                "{$name} has stopped using the shared lock — these two workspaces "
                .'have drifted over less.'
            );
        }
    }

    /**
     * Overview must never lock, or the lock has no way out.
     *
     * Every locked screen sends the reader to Overview, because that is where
     * the onboarding decision panel and the step list live. If Overview were
     * ever taken off the pre-onboarding list, the workspace would refuse every
     * section including the one that explains why.
     */
    public function test_overview_is_always_reachable(): void
    {
        $lock = $this->read('lib/vendors/workspaceLock.js');

        $this->assertMatchesRegularExpression(
            '/PRE_ONBOARDING_SECTIONS\s*=\s*\[[^\]]*\'overview\'/',
            $lock,
            'Overview is the destination every locked section offers; it cannot itself be locked.'
        );

        foreach (['profile', 'contact', 'documents'] as $section) {
            $this->assertStringContainsString(
                "'{$section}'",
                $lock,
                "The {$section} section is part of onboarding and must stay open while it runs."
            );
        }
    }
}
