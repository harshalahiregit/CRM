<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * The defect this guards was one line of JSX, and no backend test could see it.
 *
 * StaffModal's applyRole() read:
 *
 *     permissions: role ? (role.permissions || {}) : {},
 *
 * so choosing a role copied its grants into that person's meta.permissions.
 * effectiveGrants() then handed the copy the win for every module it named, and
 * the role became decoration — editing it changed nothing for its holders. The
 * server was correct throughout; the browser is where inheritance was lost.
 *
 * RolePermissionInheritanceTest covers what the server does with the two shapes.
 * This covers the part only the source can answer: that the browser still
 * produces the inheriting shape, and that the screen tells an admin which of the
 * two they are looking at.
 *
 * Read from source because the frontend has no test runner — the same approach
 * as BannedPatternsTest and StaffPermissionModuleParityTest in this directory.
 */
class StaffModalRoleLinkageTest extends TestCase
{
    private const MODAL = __DIR__.'/../../../../frontend/src/components/admin/StaffModal.jsx';

    private function source(): string
    {
        $this->assertFileExists(self::MODAL, 'StaffModal.jsx has moved — update this test to follow it.');

        return file_get_contents(self::MODAL);
    }

    private function applyRoleBody(): string
    {
        $src   = $this->source();
        $start = strpos($src, 'const applyRole = (roleId) => {');
        $this->assertNotFalse($start, 'applyRole has been renamed or removed.');

        return substr($src, $start, strpos($src, "\n  }", $start) - $start);
    }

    /**
     * The regression itself: selecting a role must not write permissions.
     */
    public function test_choosing_a_role_does_not_copy_its_permissions_into_the_user(): void
    {
        $body = $this->applyRoleBody();

        $this->assertStringNotContainsString('permissions:', $body,
            'applyRole must not set `permissions` — assigning a role is a link, and writing the '
            .'role\'s grants into meta.permissions turns every module it names into a personal '
            .'override that silently outranks the role for ever after.');

        $this->assertStringContainsString('staff_role_id', $body,
            'It must still actually assign the role.');
    }

    /** Swapping somebody\'s role is not a statement about their overrides. */
    public function test_choosing_a_role_does_not_clear_existing_overrides(): void
    {
        $this->assertStringNotContainsString('permissions:   {}', $this->applyRoleBody());
        $this->assertStringNotContainsString('permissions: {}', $this->applyRoleBody());
    }

    /**
     * Toggling an inherited module must SEED the override from what it was
     * inheriting. Starting from an empty list would strip every other capability
     * the role gave for that module the moment somebody ticked one box, because
     * overrides replace the role at module level rather than merging with it.
     */
    public function test_toggling_an_inherited_module_seeds_the_override_from_the_role(): void
    {
        $src   = $this->source();
        $start = strpos($src, 'const togglePermission = (module, action) => {');
        $this->assertNotFalse($start, 'togglePermission has been renamed or removed.');
        $body = substr($src, $start, strpos($src, "\n  }", $start) - $start);

        $this->assertStringContainsString('inheritedPermissions', $body,
            'togglePermission must fall back to the inherited grant, or a single tick wipes the rest of the module.');
        $this->assertStringContainsString('hasOwnProperty', $body,
            'It has to distinguish "module absent" from "module present but empty" — they are different answers.');
    }

    /**
     * There must be a way back. Deleting the key is the only way to express
     * "no opinion"; setting it to [] denies the module outright, which is the
     * opposite of undoing an override.
     */
    public function test_an_override_can_be_handed_back_to_the_role(): void
    {
        $src = $this->source();

        $this->assertStringContainsString('const resetModuleToRole', $src,
            'An admin who overrides a module by accident needs a way to undo it.');

        $start = strpos($src, 'const resetModuleToRole');
        $body  = substr($src, $start, strpos($src, "\n  }", $start) - $start);

        $this->assertStringContainsString('delete next[module]', $body,
            'Reset must DELETE the key. Setting it to [] would deny the module rather than inherit it.');
    }

    /**
     * The admin has to be able to tell the two apart, or "12 permissions granted"
     * is a sentence with no subject.
     */
    public function test_the_screen_distinguishes_inherited_from_overridden(): void
    {
        $src = $this->source();

        $this->assertStringContainsString('isOverridden', $src,
            'The screen must know which modules were decided for this person specifically.');

        // 'Custom' and 'Denied' are a quoted ternary; 'Role' is bare JSX text.
        // Matched as rendered rather than as a literal, so a whitespace change
        // does not fail the build and a renamed badge does.
        $this->assertMatchesRegularExpression('/>\s*Role\s*</', $src,
            'An inherited module must be badged, or an admin cannot tell where the grant came from.');

        foreach (["'Custom'", "'Denied'"] as $badge) {
            $this->assertStringContainsString($badge, $src,
                "The row badge {$badge} is how an admin sees that a module was decided for this person.");
        }
    }

    /**
     * With a role assigned, an empty override map means "inherit everything";
     * with no role it means "nothing at all". One button cannot honestly mean
     * both, so the destructive one is separate.
     */
    public function test_reset_and_deny_are_separate_actions(): void
    {
        $src = $this->source();

        $this->assertStringContainsString('Reset to Role', $src,
            'Dropping every override is not the same act as denying everything.');
        $this->assertStringContainsString('const denyAllPermissions', $src,
            'Denying everything has to write an explicit empty list per module.');
    }

    /**
     * Counts must read the EFFECTIVE grant. Counting overrides would show
     * "0 permissions" for somebody inheriting a full role and doing nothing wrong.
     */
    public function test_the_counters_report_effective_permissions(): void
    {
        $src = $this->source();

        $this->assertMatchesRegularExpression('/const totalGranted\s*=.*grantFor/', $src,
            'The header count must be effective, not the number of overrides.');
        $this->assertStringContainsString('const groupGranted = group.modules.reduce((s,m)=>s+grantFor(m.key).length,0)', $src,
            'The per-group count must be effective for the same reason.');
    }
}
