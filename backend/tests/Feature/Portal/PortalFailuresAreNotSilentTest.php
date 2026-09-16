<?php

namespace Tests\Feature\Portal;

use Tests\TestCase;

/**
 * A portal request that fails is not allowed to look like an empty account.
 *
 * Nearly every portal page fetches like this:
 *
 *     api.workers.list().then(setRows).catch(() => setRows([]))
 *
 * so a 500 renders as "no records yet". That is how the TPV portal's Gate Log,
 * Attendance and Contacts tabs stayed broken for months: each showed a vendor
 * with plenty of records an empty list, which reads as a true statement about a
 * quiet account and is therefore never reported.
 *
 * Rewriting all sixty-odd of those call sites would be a large change to pages
 * that otherwise work. The small one is this: both axios clients report the
 * failure to a shared store, and the shell the two portals share shows a banner
 * above the content. The page keeps its empty state; the person reading it is
 * told the screen is incomplete. Wrong-and-quiet becomes incomplete-and-declared.
 *
 * Guarded here because the wiring is three lines in three files and looks like
 * noise to anybody tidying up.
 */
class PortalFailuresAreNotSilentTest extends TestCase
{
    private function frontend(string $relative): string
    {
        $path = base_path('../frontend/'.$relative);
        $this->assertFileExists($path, "{$relative} has moved — this guard needs repointing");

        return (string) file_get_contents($path);
    }

    public function test_both_portal_api_clients_report_their_failures(): void
    {
        foreach ([
            'src/lib/api.js' => 'the TPV portal (and the whole admin app)',
            'src/lib/purchaseVendorApi.js' => 'the Purchase vendor portal',
        ] as $file => $who) {
            $src = $this->frontend($file);

            $this->assertStringContainsString('recordRequestFailure', $src,
                "{$who} no longer reports failed requests, so a 500 is invisible again");
            $this->assertStringContainsString('interceptors.response', $src,
                "{$who} has no response interceptor to report from");
        }
    }

    public function test_the_shared_portal_shell_shows_the_banner(): void
    {
        // One file covers both portals: PurchasePortalShell and VendorPortalShell
        // are thin descriptors over this.
        $shell = $this->frontend('src/pages/vendor-portal/PortalShell.jsx');

        $this->assertStringContainsString('DataFailureBanner', $shell,
            'the portals no longer surface failed requests to the vendor');
    }

    public function test_the_store_ignores_the_statuses_pages_handle_themselves(): void
    {
        $store = $this->frontend('src/lib/requestFailures.js');

        // 401 redirects to login and 403 is a real answer ("not your portal").
        // Reporting either would put a fault banner on a working screen.
        $this->assertStringContainsString('401', $store);
        $this->assertStringContainsString('403', $store);
        $this->assertStringContainsString('>= 500', $store,
            'the store should report server faults, not validation errors');
    }

    /**
     * The banner has to say the thing that is actually confusing.
     *
     * "Something went wrong" leaves the vendor still unsure whether the empty
     * list below is real. Naming that specifically is the entire value.
     */
    public function test_the_banner_explains_that_empty_may_not_mean_empty(): void
    {
        $banner = $this->frontend('src/components/ui/DataFailureBanner.jsx');

        $this->assertMatchesRegularExpression('/may not be empty/i', $banner,
            'the banner does not tell the vendor that an empty section might be a fault');
    }
}
