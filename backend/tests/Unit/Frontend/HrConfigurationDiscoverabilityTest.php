<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * The HR configuration system was complete and unfindable.
 *
 * Every master an administrator asked for already existed with full CRUD, and
 * every one was already reachable — but spread across seven screens, because
 * each grew inside the module that consumes it. Leave types under Leave
 * Management, shifts under HR Operations, exit types under Exit Management.
 * Reasonable from inside a module; a dead end for somebody asking "where do I
 * configure HR?".
 *
 * The sharpest case was designations. The Employees page carried TWO controls
 * labelled "Designation": a filter derived from the values employees actually
 * hold, and a form fed by the master. This tenant has fifteen designations on
 * record and eight in use, so the filter showed the shorter list — and an
 * administrator checking whether "Manager" existed found it absent, concluded
 * the catalogue was fixed, and asked for a feature that had shipped.
 *
 * These tests hold the FIX in place: the labels that distinguish the two, the
 * index that says where each master lives, and the pointer that separates an
 * employee record from a staff account. Source-level, because the frontend has
 * no test runner — the same approach as HrActionVisibilityTest beside this file.
 *
 * None of this changes authorization. The server-side boundary is covered by
 * HrStaffAccountGateTest, ScopedListingTest and FrontendCapabilityPayloadTest.
 */
class HrConfigurationDiscoverabilityTest extends TestCase
{
    private const SRC = __DIR__.'/../../../../frontend/src';

    private function read(string $path): string
    {
        $full = self::SRC.'/'.$path;
        $this->assertFileExists($full, "{$path} has moved — update this test to follow it.");

        return file_get_contents($full);
    }

    /* ── 1. Filter vs master: the two "Designation" labels ────────────── */

    /**
     * The filter says it filters.
     *
     * Both controls sat on one screen under one word. Renaming the filter is
     * the whole fix: the form keeps the plain noun because it sets the value,
     * and the filter now names the question it answers.
     */
    public function test_the_employee_filters_say_they_are_filters(): void
    {
        $src = $this->read('modules/hr/pages/Employees.jsx');

        $this->assertStringContainsString('Filter by department', $src,
            'The department FILTER must not share the bare label "Department" with the form field — that ambiguity is the bug.');
        $this->assertStringContainsString('Filter by designation', $src,
            'The designation FILTER must not share the bare label "Designation" with the form field.');
    }

    /** The form still sets the value, so it keeps the plain label. */
    public function test_the_employee_form_keeps_the_plain_labels(): void
    {
        $src = $this->read('modules/hr/pages/Employees.jsx');

        $this->assertStringContainsString('Department *', $src);
        $this->assertStringContainsString('Designation *', $src);
    }

    /**
     * The filter is still derived from employees on screen, and the form is
     * still fed by the masters. Renaming must not have quietly swapped them:
     * a filter listing values nobody holds returns an empty table every time.
     */
    public function test_filter_and_form_still_read_from_different_sources(): void
    {
        $src = $this->read('modules/hr/pages/Employees.jsx');

        $this->assertMatchesRegularExpression(
            '/const designations = useMemo\(\(\)=>\[.All., \.\.\.new Set\(optionsList\.map/',
            $src,
            'The filter must stay derived from the employees on screen.'
        );
        $this->assertStringContainsString('useMasterData()', $src,
            'The form must stay fed by the shared master-data cache.');
    }

    /* ── 2. A way to add what is missing ──────────────────────────────── */

    /**
     * The route to Organization Setup is offered ALWAYS, not only when the
     * list is empty.
     *
     * A missing-but-wanted entry looks exactly like a full list to the person
     * who wants it. The empty case only ever needed the loudest version of a
     * signpost every case needs.
     */
    public function test_organization_setup_is_offered_even_when_the_lists_are_full(): void
    {
        $src = $this->read('modules/hr/pages/Employees.jsx');

        $this->assertStringContainsString('Manage departments in Organization Setup', $src);
        $this->assertStringContainsString('Manage designations in Organization Setup', $src);

        // The old form gated the link behind an empty list.
        $this->assertStringNotContainsString('{!deptNames.length && (', $src,
            'The link must no longer be conditional on the master being empty.');
        $this->assertStringNotContainsString('{!desigNames.length && (', $src,
            'The link must no longer be conditional on the master being empty.');
    }

    /* ── 3. The index exists, is routed, and is in the sidebar ────────── */

    public function test_the_hr_configuration_page_exists_and_is_routed(): void
    {
        $page = $this->read('modules/hr/pages/HrConfiguration.jsx');
        $this->assertStringContainsString('HR Configuration', $page);

        $routes = $this->read('app/routes.jsx');
        $this->assertStringContainsString('HrConfiguration', $routes, 'The page must be imported.');
        $this->assertStringContainsString('path="configuration"', $routes, 'The page must be routed.');
    }

    public function test_the_index_is_reachable_from_the_sidebar(): void
    {
        $sidebar = $this->read('components/layout/Sidebar.jsx');

        $this->assertStringContainsString('/app/hr/configuration', $sidebar,
            'A page nobody can navigate to has not been made discoverable.');
        $this->assertStringContainsString("label: 'HR Configuration'", $sidebar);
    }

    /**
     * Every destination the index offers is a route that exists.
     *
     * An index of dead links is worse than no index: it converts "I cannot
     * find it" into "it is broken".
     */
    public function test_every_link_on_the_index_points_at_a_real_route(): void
    {
        $page   = $this->read('modules/hr/pages/HrConfiguration.jsx');
        $routes = $this->read('app/routes.jsx');

        preg_match_all("#to: '/app/hr/([a-z-]+)'#", $page, $m);
        $this->assertNotEmpty($m[1], 'The index should link somewhere.');

        foreach (array_unique($m[1]) as $segment) {
            $this->assertStringContainsString(
                'path="'.$segment.'"',
                $routes,
                "HrConfiguration links to /app/hr/{$segment}, which is not a declared route."
            );
        }

        // The one non-HR destination, guarded separately below.
        $this->assertStringContainsString("to: '/app/admin/staff'", $page);
        $this->assertStringContainsString('path="staff"', $routes);
    }

    /* ── 4. Masters with no backend are not invented ──────────────────── */

    /**
     * Employment Types have no table and no endpoint; Business Units and
     * Locations are derived at read time and cannot be edited. The page says
     * so rather than offering a control that leads nowhere.
     */
    public function test_absent_masters_are_stated_not_faked(): void
    {
        $page = $this->read('modules/hr/pages/HrConfiguration.jsx');

        $this->assertStringContainsString('Not configurable yet', $page);
        $this->assertStringContainsString('Employment Types', $page);
        $this->assertStringContainsString('Business Units', $page);

        foreach (['/app/hr/employment-types', '/app/hr/business-units', '/app/hr/locations'] as $fake) {
            $this->assertStringNotContainsString($fake, $page,
                "{$fake} has no backend — it must not be offered as a destination.");
        }
    }

    /* ── 5. Employee record vs staff account ──────────────────────────── */

    /**
     * The distinction that started all of this, made visible.
     *
     * It had existed only as a code comment, which is exactly the audience
     * that did not need telling.
     */
    public function test_the_employee_form_points_at_staff_management(): void
    {
        $src = $this->read('modules/hr/pages/Employees.jsx');

        $this->assertStringContainsString('Looking for CRM access and permissions?', $src);
        $this->assertStringContainsString("navigate('/app/admin/staff')", $src);
    }

    /**
     * Both new admin-only surfaces read the SERVER's verdict.
     *
     * permissions.is_admin comes from StaffPermissionService::bypasses(), whose
     * BYPASS_ROLES is exactly ['admin'] — the same test role:admin applies to
     * /api/admin/*. Re-deriving the rule in the client is how a link starts
     * promising a page the API will refuse.
     */
    public function test_the_admin_only_surfaces_use_the_server_supplied_flag(): void
    {
        $employees = $this->read('modules/hr/pages/Employees.jsx');
        $index     = $this->read('modules/hr/pages/HrConfiguration.jsx');

        $this->assertStringContainsString('{isAdmin && (', $employees,
            'The Staff Management pointer must be gated by the server-supplied isAdmin.');
        $this->assertStringContainsString('const { isAdmin } = useAuth()', $index,
            'The index must read isAdmin from the auth context, not re-derive it.');

        foreach ([$employees, $index] as $src) {
            $this->assertStringNotContainsString("user?.role === 'admin'", $src,
                'Account type must not be re-derived client-side; use the server-supplied flag.');
        }
    }

    /* ── 6. The retired-route comment no longer misdirects ────────────── */

    /**
     * /admin/roles is the API the Roles button calls. It is not a page, and
     * naming it as the place roles are "maintained" sent administrators
     * looking for a route that has never existed.
     */
    public function test_the_retired_roles_comment_names_a_real_screen(): void
    {
        $routes = $this->read('app/routes.jsx');

        $this->assertStringContainsString('/app/admin/staff', $routes,
            'The retired-roles note must point at the real Staff Management route.');
        $this->assertDoesNotMatchRegularExpression(
            '/maintained in Staff\s+Management at \/admin\/roles/',
            $routes,
            'The note must no longer present the API path as the place to go.'
        );
    }
}
