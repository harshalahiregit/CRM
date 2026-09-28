<?php

namespace Tests\Feature\Shared;

use Tests\TestCase;

/**
 * Purchase and TPV have ONE navigation, and it is the sidebar.
 *
 * Both modules used to draw their tree twice. The sidebar listed the sections;
 * ModuleShell repeated those same section names in a pill rail across the top
 * of every page, and opened a second rail underneath for whichever one was
 * active. So "Vendors" and "Vendor Master" each appeared twice on one screen,
 * in two shapes, and the two lists were maintained separately — Purchase's
 * sidebar copy had drifted into a flat column of fifty pages using different
 * labels and a different order from the clusters above it. "Workforce" named
 * two different screens depending on which of the two you happened to read.
 *
 * The tree now lives in purchaseNav.js / tpvNav.js and is rendered once. These
 * assertions are here because nothing about a second navigation is a runtime
 * error: it builds, it works, every link goes somewhere. It is only wrong to
 * look at, which no automated check catches and every person using it notices.
 */
class OneNavigationNotTwoTest extends TestCase
{
    private function src(string $relative): string
    {
        $path = base_path('../frontend/src/'.$relative);
        $this->assertFileExists($path, "{$relative} has moved — this guard needs repointing");

        return (string) file_get_contents($path);
    }

    /** The tree is defined in one place per module, and it is a data file. */
    public function test_each_module_tree_has_a_single_definition(): void
    {
        foreach ([
            'modules/purchase/purchaseNav.js' => 'PURCHASE_GROUPS',
            'modules/tpv/tpvNav.js' => 'TPV_GROUPS',
        ] as $file => $symbol) {
            $this->assertStringContainsString("export const {$symbol}", $this->src($file),
                "{$file} no longer exports {$symbol}; the sidebar and the module layout both read it, "
                .'and a module that keeps its nav inside a component is the shape that let two rival '
                .'copies of the same tree exist in the first place');
        }
    }

    /** Both consumers import it rather than restating it. */
    public function test_the_sidebar_and_the_layouts_read_that_one_definition(): void
    {
        $sidebar = $this->src('components/layout/Sidebar.jsx');

        $this->assertStringContainsString("from '@/modules/purchase/purchaseNav'", $sidebar);
        $this->assertStringContainsString("from '@/modules/tpv/tpvNav'", $sidebar);

        $this->assertStringContainsString('PURCHASE_GROUPS',
            $this->src('modules/purchase/PurchaseLayout.jsx'));
        $this->assertStringContainsString('TPV_GROUPS',
            $this->src('modules/tpv/TPVLayout.jsx'));
    }

    /**
     * And the module page paints no rail of its own when it was handed a tree.
     *
     * Flat modules (the vendor Workforce shells) still pass `items` and keep
     * their single rail — they have no sidebar tree to duplicate, so there is
     * nothing there to say twice.
     */
    public function test_a_grouped_module_renders_no_tab_rail_above_the_page(): void
    {
        $shell = $this->src('components/layout/ModuleShell.jsx');

        $this->assertStringContainsString('{groups ? null : (', $shell,
            'ModuleShell is rendering something again for grouped modules. The sidebar already '
            .'shows that tree; anything drawn here is the second copy of it.');

        $this->assertDoesNotMatchRegularExpression(
            '/groups\.map\(/', $shell,
            'ModuleShell is mapping over the groups again — that is the cluster rail coming back',
        );
    }

    /**
     * A breadcrumb is allowed, and is in fact the point: it says where you are
     * without offering a second way to get somewhere else.
     */
    public function test_the_page_still_says_where_you_are(): void
    {
        $shell = $this->src('components/layout/ModuleShell.jsx');

        $this->assertStringContainsString('activeGroup', $shell);
        $this->assertStringContainsString('activeItem', $shell);
    }
}
