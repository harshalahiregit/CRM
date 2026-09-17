<?php

namespace Tests\Feature\Shared;

use Tests\TestCase;

/**
 * A modal taller than the screen scrolls. It does not squash.
 *
 * `.pr-overlay-panel` is the shell behind every Purchase, TPV and Inventory
 * dialog: a flex column, capped at 90vh, with overflowY:auto. A flex item
 * defaults to flex-shrink:1 — so once the content is taller than the cap, the
 * browser shrinks the children to fit instead of scrolling, and it takes the
 * height from whichever child can give it. That is never the row of fixed-height
 * inputs; it is the one element whose height comes from its own content.
 *
 * On the Purchase Request and Purchase Order forms that element is the line-item
 * table. Squashed to a sliver and then clipped to nothing by `.pr-glass`'s
 * overflow:hidden, it produced a form with a "Line Items" heading, a catalog
 * search reading "type your items in the table below", and no table anywhere —
 * and only on a short window, which is why it looked correct to whoever built
 * it and to every screenshot taken on a tall monitor. Adding a line still
 * worked. The new row was inside the collapsed box.
 *
 * The whole failure is one missing declaration, and it is invisible in every
 * JSX file involved — which is why it is asserted here rather than left to be
 * rediscovered the next time somebody says a button does nothing.
 */
class TallModalsScrollRatherThanSquashTest extends TestCase
{
    private function kit(): string
    {
        $path = base_path('../frontend/src/components/ui/kit3d.jsx');
        $this->assertFileExists($path, 'kit3d.jsx has moved — this guard needs repointing');

        return (string) file_get_contents($path);
    }

    public function test_overlay_children_are_not_allowed_to_shrink(): void
    {
        $css = $this->kit();

        $this->assertMatchesRegularExpression(
            '/\.pr-overlay-panel\s*>\s*\*\s*\{[^}]*flex-shrink:\s*0/',
            $css,
            'the overlay panel no longer pins flex-shrink:0 on its children. It is a flex column '
            .'with a height cap, so without this the browser squashes whichever child gets its '
            .'height from content — the line-item table — and .pr-glass clips the remains to '
            .'nothing. The form then shows no table and no way to notice why.',
        );
    }

    /**
     * The two halves of the bug have to stay in view together: the panel is only
     * dangerous because it is a height-capped flex column.
     */
    public function test_the_panel_is_still_the_flex_column_that_makes_this_necessary(): void
    {
        $css = $this->kit();

        $start = strpos($css, '.pr-overlay-panel {');
        $this->assertNotFalse($start);

        $block = substr($css, $start, 400);

        if (! str_contains($block, 'display: flex')) {
            $this->markTestSkipped('the overlay is no longer a flex container — the shrink guard may be moot');
        }

        $this->assertStringContainsString('flex-direction: column', $block);
    }

    /**
     * And the line-item tables must keep an empty state, so a form with no rows
     * says so instead of showing a bare header that reads as "broken".
     */
    public function test_the_line_item_tables_say_when_they_are_empty(): void
    {
        foreach (['PurchaseRequests', 'PurchaseOrders'] as $page) {
            $src = (string) file_get_contents(
                base_path("../frontend/src/modules/purchase/pages/{$page}.jsx"),
            );

            $this->assertStringContainsString('No lines yet', $src,
                "{$page} no longer tells the user when the line table is empty — an empty table with "
                .'only a header row is the state people report as a broken form');

            $this->assertStringContainsString('lineItems', $src,
                "{$page} no longer guards its item list; a form handed no items array would throw "
                .'inside the one control that lets somebody type a line by hand');
        }
    }
}
