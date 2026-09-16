<?php

namespace Tests\Feature\Shared;

use Tests\TestCase;

/**
 * The TPV and Purchase vendor sidebars are the same list.
 *
 * They are the same job done against two vendor masters, and every place they
 * drifted apart cost somebody time working out whether a missing entry meant a
 * missing feature or just a different word. Quotation vs Quotations. Debit Note
 * vs Debit Notes. Project vs Projects. Referral vs Referrals. Contact vs
 * Contacts. A whole group called Operations on one side and Execution on the
 * other. And a Purchase tab named Onboarding whose second card was the same
 * document list as the tab named Documents.
 *
 * None of that is a feature difference. All of it reads as one.
 *
 * So this asserts the lists match exactly — same groups, same order, same
 * labels. A real difference in what a module can do shows up as an item the
 * layout does not render, because TAB_ELEMENTS has no entry for it; it does not
 * show up as a different word for the same thing.
 */
class VendorSidebarsAreIdenticalTest extends TestCase
{
    private function frontend(string $relative): string
    {
        $path = base_path('../frontend/'.$relative);
        $this->assertFileExists($path, "{$relative} has moved — this guard needs repointing");

        return (string) file_get_contents($path);
    }

    /** @return array<string, string[]> group title => item labels, in order */
    private function tpvGroups(): array
    {
        $src = $this->frontend('src/modules/tpv/pages/TpvVendorDetail.jsx');

        preg_match_all(
            "/\{\s*group:\s*'([^']+)',[^\[]*items:\s*\[([^\]]+)\]/",
            $src, $m, PREG_SET_ORDER,
        );

        $out = [];
        foreach ($m as $g) {
            preg_match_all("/'([^']+)'/", $g[2], $items);
            $out[$g[1]] = $items[1];
        }

        return $out;
    }

    /** @return array<string, string[]> */
    private function purchaseGroups(): array
    {
        $src = $this->frontend('src/modules/purchase/pages/vendor-detail/vendorDetailNav.jsx');

        $out = [];
        foreach (array_slice(preg_split("/title:\s*'/", $src), 1) as $chunk) {
            $title = substr($chunk, 0, strpos($chunk, "'"));
            preg_match_all("/label:\s*'([^']+)'/", $chunk, $items);
            if ($items[1]) {
                $out[$title] = $items[1];
            }
        }

        return $out;
    }

    public function test_the_two_sidebars_have_the_same_groups_in_the_same_order(): void
    {
        $this->assertSame(
            array_keys($this->tpvGroups()),
            array_keys($this->purchaseGroups()),
            'the two vendor workspaces group their sections differently',
        );
    }

    public function test_every_group_holds_the_same_items_in_the_same_order(): void
    {
        $tpv = $this->tpvGroups();
        $pur = $this->purchaseGroups();

        foreach ($tpv as $group => $items) {
            $this->assertArrayHasKey($group, $pur, "Purchase has no {$group} group");

            $this->assertSame($items, $pur[$group],
                "the {$group} group differs between TPV and Purchase — if one module gained a "
                .'section the other should list it too, and if it is only a different word for the '
                .'same screen it should not be a different word');
        }
    }

    public function test_neither_sidebar_carries_an_item_the_other_lacks(): void
    {
        $flat = fn (array $groups) => array_merge(...array_values($groups));

        $tpv = $flat($this->tpvGroups());
        $pur = $flat($this->purchaseGroups());

        $this->assertSame([], array_values(array_diff($tpv, $pur)),
            'TPV lists sections Purchase does not: '.implode(', ', array_diff($tpv, $pur)));
        $this->assertSame([], array_values(array_diff($pur, $tpv)),
            'Purchase lists sections TPV does not: '.implode(', ', array_diff($pur, $tpv)));
    }

    /**
     * One tab, one heading.
     *
     * The Purchase Onboarding tab rendered a second copy of the vendor's
     * document checklist under its own heading, while Compliance › Documents
     * showed the same list from the same endpoint. Two views of one set of
     * papers, with nothing saying they were the same papers.
     */
    public function test_the_onboarding_tab_does_not_repeat_the_documents_tab(): void
    {
        $tabs = $this->frontend('src/modules/purchase/pages/vendor-detail/vendorDetailTabs.jsx');

        $start = strpos($tabs, 'export function OnboardingTab');
        $this->assertNotFalse($start, 'OnboardingTab has moved');

        $body = substr($tabs, $start, 1800);

        $this->assertStringNotContainsString('PurchaseVendorDocumentsReadOnly', $body,
            'the Onboarding tab is showing the document checklist again — Compliance › Documents is that list');
    }

    /** The two vendor PORTALS name their sections the same way too. */
    public function test_the_two_vendor_portals_use_one_vocabulary(): void
    {
        $labels = function (string $file) {
            preg_match_all("/label:\s*'([^']+)'[^}]*?to:\s*'([^']+)'/", $this->frontend($file), $m);

            return $m[1];
        };

        $this->assertSame(
            $labels('src/pages/vendor-portal/VendorPortalShell.jsx'),
            $labels('src/pages/purchase-portal/PurchasePortalShell.jsx'),
            'the two vendor portals label their sections differently — a supplier working under '
            .'both engines sees one feature under two names',
        );
    }
}
