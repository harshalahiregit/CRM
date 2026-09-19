<?php

namespace Tests\Unit\Frontend;

use App\Support\Hr\StaffPermission;
use PHPUnit\Framework\TestCase;

/**
 * One permission vocabulary, kept in three places, checked here.
 *
 * A module has to be named in all three or it does not work:
 *
 *   StaffPermission::MODULES   — what the server will store. sanitise() drops
 *                                anything else on the way in AND on the way out,
 *                                so a key missing here can never be granted.
 *   PERMISSION_MODULES         — the matrix rows, and which capabilities each
 *                                offers.
 *   MODULE_GROUPS              — the collapsible sections. A key in no group
 *                                NEVER RENDERS, whatever the matrix says.
 *
 * All three had already drifted: hr_attendance and self were storable and
 * grantable via a role template, but had no row and no group, so no admin could
 * see or set them. Nothing failed — the box simply was not there, which is the
 * kind of gap that is only found by going looking.
 *
 * Read from source rather than exercised through a browser because the frontend
 * has no test runner; BannedPatternsTest in this directory does the same.
 */
class StaffPermissionModuleParityTest extends TestCase
{
    private const MODAL = __DIR__.'/../../../../frontend/src/components/admin/StaffModal.jsx';

    private function modalSource(): string
    {
        $this->assertFileExists(self::MODAL, 'StaffModal.jsx has moved — update this test to follow it.');

        return file_get_contents(self::MODAL);
    }

    /** The keys of the PERMISSION_MODULES matrix, in file order. */
    private function matrixKeys(): array
    {
        $src   = $this->modalSource();
        $start = strpos($src, 'const PERMISSION_MODULES = [');
        $this->assertNotFalse($start, 'PERMISSION_MODULES has been renamed or removed.');

        $block = substr($src, $start, strpos($src, "\n]", $start) - $start);
        // Digits included: hr_manpower_l1 and hr_manpower_l2 name the two rungs
        // of the manpower ladder, and a pattern that could not see them read as
        // "this module has no checkbox" when it had one.
        preg_match_all("/key:\s*'([a-z0-9_]+)'/", $block, $m);

        return $m[1];
    }

    /** Every key mentioned by any collapsible group. */
    private function groupedKeys(): array
    {
        $src   = $this->modalSource();
        $start = strpos($src, 'const MODULE_GROUPS = [');
        $this->assertNotFalse($start, 'MODULE_GROUPS has been renamed or removed.');

        $block = substr($src, $start, strpos($src, "\n  ]", $start) - $start);
        preg_match_all("/'([a-z0-9_]+)'/", $block, $m);

        // Group LABELS are capitalised, so the lowercase matches are the keys.
        return array_values(array_unique($m[1]));
    }

    public function test_every_server_module_has_a_row_in_the_matrix(): void
    {
        $missing = array_diff(StaffPermission::MODULES, $this->matrixKeys());

        $this->assertSame([], array_values($missing),
            'These modules can be granted by the server but have no checkbox, so no admin can set them: '
            .implode(', ', $missing));
    }

    public function test_every_matrix_row_is_a_real_server_module(): void
    {
        $unknown = array_diff($this->matrixKeys(), StaffPermission::MODULES);

        $this->assertSame([], array_values($unknown),
            'These rows can be ticked but sanitise() discards them, so the box comes back empty with no error: '
            .implode(', ', $unknown));
    }

    /**
     * The half that is easy to forget: a row in no group is invisible.
     */
    public function test_every_module_appears_in_a_collapsible_group(): void
    {
        $ungrouped = array_diff(StaffPermission::MODULES, $this->groupedKeys());

        $this->assertSame([], array_values($ungrouped),
            'These modules have a matrix row but belong to no group, so they never render: '
            .implode(', ', $ungrouped));
    }

    public function test_no_group_names_a_module_that_does_not_exist(): void
    {
        $unknown = array_diff($this->groupedKeys(), StaffPermission::MODULES);

        $this->assertSame([], array_values($unknown),
            'These keys are grouped but are not modules — they will render nothing: '.implode(', ', $unknown));
    }

    /** Each row may only offer capabilities the server recognises. */
    public function test_every_offered_capability_is_a_real_capability(): void
    {
        $src   = $this->modalSource();
        $start = strpos($src, 'const PERMISSION_MODULES = [');
        $block = substr($src, $start, strpos($src, "\n]", $start) - $start);

        preg_match_all("/key:\s*'([a-z0-9_]+)'.*?actions:\s*\[([^\]]*)\]/s", $block, $rows, PREG_SET_ORDER);
        $this->assertNotEmpty($rows, 'No matrix rows parsed — the shape of PERMISSION_MODULES has changed.');

        foreach ($rows as $row) {
            preg_match_all("/'([a-z_]+)'/", $row[2], $caps);

            foreach ($caps[1] as $capability) {
                $this->assertTrue(
                    StaffPermission::isCapability($capability),
                    "Module '{$row[1]}' offers '{$capability}', which is not a capability — ticking it saves nothing."
                );
            }
        }
    }

    /**
     * The four HR modules this step exists to add.
     *
     * Named explicitly rather than counted, so the test says what is missing
     * instead of "expected 29, got 28".
     */
    public function test_the_hr_vocabulary_covers_employees_payroll_leave_and_exit(): void
    {
        foreach (['hr_employees', 'hr_payroll', 'hr_leave', 'hr_exit'] as $module) {
            $this->assertTrue(StaffPermission::isModule($module),
                "'{$module}' must be expressible — without it there is no way to describe that part of HR.");
            $this->assertContains($module, $this->matrixKeys(), "'{$module}' has no checkbox.");
            $this->assertContains($module, $this->groupedKeys(), "'{$module}' is in no group, so it never renders.");
        }
    }

    /**
     * Adding vocabulary must not grant anything.
     *
     * The whole safety of this step rests on it: a user with no grid and no role
     * still has nothing, and a stored grid is unchanged by the new keys existing.
     */
    public function test_naming_a_module_does_not_grant_it(): void
    {
        $this->assertSame([], StaffPermission::sanitise([]));

        $stored = ['hr_recruitment' => ['view_global'], 'tasks' => ['view_own', 'create']];
        $this->assertSame($stored, StaffPermission::sanitise($stored),
            'An existing grid must survive the vocabulary change byte for byte.');

        $withNew = StaffPermission::sanitise($stored + ['hr_payroll' => []]);
        $this->assertSame([], $withNew['hr_payroll'],
            'An empty list is kept and means "may do nothing here" — it is how an admin overrides a role.');
    }
}
