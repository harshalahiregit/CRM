<?php

namespace Tests\Feature\Sales;

use App\Models\Sales\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may edit a lead — the first module to read the staff permission grid.
 *
 * The grid has been stored in users.meta.permissions since Staff Management
 * shipped and never been consulted. Leads are the first module to consult it,
 * so the question these tests answer is not "does the grid work" but "does
 * switching a module onto it take work away from people who do it today".
 *
 * That is the whole risk. Every account in this system has an empty grid,
 * because nothing read it and so nobody filled it in; enforcing it literally
 * would have locked everyone out at once. The grandfather rule is what prevents
 * that, and the first two tests are the ones that would catch its loss.
 *
 * SIR-000032 asked for the edit form AND for the access to be controllable by an
 * admin. Both halves are here: it is editable at all, and who may is a decision
 * an admin can make and this endpoint honours.
 */
class LeadEditPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    /** Whoever created the lead. Never the actor, so authorship cannot be what grants edit. */
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'lead-edit-t',
            'subdomain' => 'leadedit', 'status' => 'active',
        ])->save();

        $this->owner = $this->user('staff');
    }

    /** @param array<string,array<string>>|null $permissions */
    private function user(string $role, ?array $permissions = null): User
    {
        return User::create([
            'tenant_id' => self::TENANT,
            'name'      => ucfirst($role),
            'email'     => $role.uniqid().'@test.com',
            'password'  => bcrypt('secret'),
            'role'      => $role,
            'status'    => 'active',
            'meta'      => $permissions === null ? [] : ['permissions' => $permissions],
        ]);
    }

    private function lead(): Lead
    {
        return Lead::create([
            'tenant_id'  => self::TENANT,
            'created_by' => $this->owner->id,
            'name'       => 'Meridian Textiles',
            'email'      => 'buyer@meridian.test',
        ]);
    }

    private function rename(Lead $lead, string $to)
    {
        return $this->putJson("/api/sales/leads/{$lead->id}", ['name' => $to]);
    }

    /* ── The grandfather rule ───────────────────────────────────── */

    /**
     * The regression that would hurt: everyone's grid is empty today, so a
     * literal reading of it would take lead editing away from the whole company
     * on the strength of boxes nobody knew were load-bearing.
     */
    public function test_an_unconfigured_user_may_still_edit(): void
    {
        Sanctum::actingAs($this->user('staff'));
        $lead = $this->lead();

        $this->rename($lead, 'Meridian Textiles Pvt Ltd')->assertOk();

        $this->assertSame('Meridian Textiles Pvt Ltd', $lead->fresh()->name);
    }

    /**
     * The grandfather is about silence, not about leads. Once an admin has
     * configured ANY module for somebody, they have started expressing opinions
     * and this endpoint stops guessing on their behalf.
     *
     * Deliberately tested with a module that has nothing to do with sales: were
     * the check per-module rather than whole-grid, setting someone up for tasks
     * alone would silently hand them the sales pipeline too.
     */
    public function test_configuring_an_unrelated_module_ends_the_grandfather(): void
    {
        Sanctum::actingAs($this->user('staff', ['tasks' => ['edit']]));
        $lead = $this->lead();

        $this->rename($lead, 'Renamed')->assertForbidden();

        $this->assertSame('Meridian Textiles', $lead->fresh()->name);
    }

    /* ── The grid, once it is being read ────────────────────────── */

    public function test_a_granted_user_may_edit(): void
    {
        Sanctum::actingAs($this->user('staff', ['deals' => ['edit']]));
        $lead = $this->lead();

        $this->rename($lead, 'Granted')->assertOk();

        $this->assertSame('Granted', $lead->fresh()->name);
    }

    /**
     * An empty capability list is not the same as an absent module.
     * StaffPermission::sanitise keeps it deliberately, and it means "this person
     * may do nothing here" — a refusal somebody typed, not one we inferred.
     */
    public function test_an_explicitly_emptied_grant_refuses(): void
    {
        Sanctum::actingAs($this->user('staff', ['deals' => []]));
        $lead = $this->lead();

        $this->rename($lead, 'Denied')->assertForbidden();

        $this->assertSame('Meridian Textiles', $lead->fresh()->name);
    }

    /**
     * The admin bypass, copied from the old CRM's tblstaff.admin for the reason
     * StaffPermissionService gives: an administrator who must tick 115 boxes to
     * do their job is one mis-configuration away from being locked out of the
     * screen that fixes it.
     */
    public function test_an_admin_bypasses_the_grid_entirely(): void
    {
        Sanctum::actingAs($this->user('admin', ['deals' => []]));
        $lead = $this->lead();

        $this->rename($lead, 'Admin override')->assertOk();

        $this->assertSame('Admin override', $lead->fresh()->name);
    }

    /* ── What the screen is told ────────────────────────────────── */

    /**
     * The button and the gate are decided by one method, so they cannot drift.
     * If this pair ever disagrees, the UI is offering a control the API refuses
     * — which is worse than not offering it at all.
     */
    public function test_the_payload_tells_the_screen_which_way_it_went(): void
    {
        $lead = $this->lead();

        Sanctum::actingAs($this->user('staff'));
        $this->getJson("/api/sales/leads/{$lead->id}")->assertOk()->assertJsonPath('can_edit', true);

        Sanctum::actingAs($this->user('staff', ['tasks' => ['edit']]));
        $this->getJson("/api/sales/leads/{$lead->id}")->assertOk()->assertJsonPath('can_edit', false);
    }

    /**
     * show() was rewritten to spread the model and append can_edit. A spread
     * that dropped a relation would break every tab on the lead profile at once,
     * so the relations LeadService::show loads are asserted to survive it.
     */
    public function test_the_rewritten_show_still_carries_its_relations(): void
    {
        Sanctum::actingAs($this->user('staff'));
        $lead = $this->lead();

        $this->getJson("/api/sales/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonStructure(['id', 'name', 'proposals', 'questionnaire_responses', 'notes', 'activities']);
    }
}
