<?php

namespace Tests\Feature\Tpv;

use App\Models\Helpdesk\Ticket;
use App\Models\Project\Project;
use App\Models\Project\ProjectExpense;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Tickets and Expenses sections of the TPV vendor detail page.
 *
 * Neither record type has a vendor of its own, and none was invented. Both reach
 * a vendor through the SAME hop:
 *
 *     TPV vendor → projects (vendor_id + link_type) → tickets.project_id
 *                                                   → project_expenses.project_id
 *
 * So what these tests pin is that hop's precision. The security boundary is the
 * point: two vendors, each with a project, each with a ticket and an expense —
 * asking for one must never return the other's, in either direction.
 */
class TpvVendorTicketsExpensesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER  = 2;

    /** @var array<int, User> */
    private array $creators = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT => 't1', self::OTHER => 't2'] as $id => $slug) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => strtoupper($slug), 'slug' => $slug,
                'subdomain' => $slug, 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role, int $tenantId = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function creator(int $tenantId): User
    {
        return $this->creators[$tenantId] ??= $this->user('admin', $tenantId);
    }

    private function vendor(string $name, int $tenantId = self::TENANT): Vendor
    {
        return Vendor::create([
            'tenant_id' => $tenantId, 'company_name' => $name,
            'email' => strtolower($name).'@test.local', 'status' => 'Active',
        ]);
    }

    private function project(string $name, ?int $vendorId, ?string $linkType, int $tenantId = self::TENANT): Project
    {
        $p = (new Project())->forceFill([
            'tenant_id'  => $tenantId,
            'name'       => $name,
            'status'     => 'in_progress',
            'progress'   => 10,
            'start_date' => '2026-01-01',
            'created_by' => $this->creator($tenantId)->id,
            'vendor_id'  => $vendorId,
            'link_type'  => $linkType,
        ]);
        $p->save();

        return $p;
    }

    private function ticket(string $subject, ?int $projectId, int $tenantId = self::TENANT): Ticket
    {
        $t = (new Ticket())->forceFill([
            'tenant_id'  => $tenantId,
            'subject'    => $subject,
            'status'     => 'open',
            'priority'   => 'medium',
            'project_id' => $projectId,
            'created_by' => $this->creator($tenantId)->id,
        ]);
        $t->save();

        return $t;
    }

    private function expense(string $title, int $projectId, float $amount, int $tenantId = self::TENANT): ProjectExpense
    {
        return ProjectExpense::create([
            'tenant_id'    => $tenantId,
            'project_id'   => $projectId,
            'title'        => $title,
            'category'     => 'Travel',
            'amount'       => $amount,
            'expense_date' => '2026-02-01',
            'billable'     => true,
            'created_by'   => $this->creator($tenantId)->id,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function ticketsFor(int $vendorId, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->user('admin'));

        return collect($this->getJson('/api/helpdesk/tickets?vendor_id='.$vendorId)->assertOk()->json('data'))
            ->pluck('subject');
    }

    private function expensesFor(int $vendorId, ?User $as = null): array
    {
        Sanctum::actingAs($as ?? $this->user('admin'));

        return $this->getJson('/api/projects/expenses?vendor_id='.$vendorId)->assertOk()->json('data');
    }

    /**
     * The boundary this whole step exists to hold. Two vendors, two projects,
     * one ticket and one expense each — neither may ever see the other's.
     *
     * @return array{0: Vendor, 1: Vendor}
     */
    private function twoVendorWorld(): array
    {
        $a  = $this->vendor('VendorA');
        $b  = $this->vendor('VendorB');
        $pa = $this->project('Project A', $a->id, 'tpv_vendor');
        $pb = $this->project('Project B', $b->id, 'tpv_vendor');

        $this->ticket('Ticket A', $pa->id);
        $this->ticket('Ticket B', $pb->id);
        $this->expense('Expense A', $pa->id, 100);
        $this->expense('Expense B', $pb->id, 200);

        return [$a, $b];
    }

    /* ── Tickets ─────────────────────────────────────────────────── */

    public function test_tickets_from_the_vendors_projects_are_returned(): void
    {
        [$a] = $this->twoVendorWorld();

        $this->assertContains('Ticket A', $this->ticketsFor($a->id));
    }

    public function test_another_vendors_tickets_are_never_returned(): void
    {
        [$a, $b] = $this->twoVendorWorld();

        $this->assertNotContains('Ticket B', $this->ticketsFor($a->id), "Vendor A must not see B's ticket.");
        $this->assertNotContains('Ticket A', $this->ticketsFor($b->id), "Vendor B must not see A's ticket.");
    }

    /** A ticket on a project with no vendor link belongs to nobody's tab. */
    public function test_tickets_from_unrelated_projects_are_excluded(): void
    {
        $v  = $this->vendor('LinkedCo');
        $p  = $this->project('Linked Project', $v->id, 'tpv_vendor');
        $up = $this->project('Internal Project', null, null);

        $this->ticket('Vendor Ticket', $p->id);
        $this->ticket('Internal Ticket', $up->id);
        $this->ticket('Projectless Ticket', null);

        $names = $this->ticketsFor($v->id);

        $this->assertContains('Vendor Ticket', $names);
        $this->assertNotContains('Internal Ticket', $names);
        $this->assertNotContains('Projectless Ticket', $names);
    }

    /**
     * vendor_id is only unique WITHIN a link type — the same integer is another
     * company under 'purchase_vendor'. Its tickets must not surface here.
     */
    public function test_a_purchase_vendor_projects_tickets_are_excluded(): void
    {
        $v  = $this->vendor('SharedIdCo');
        $pt = $this->project('TPV Project', $v->id, 'tpv_vendor');
        $pp = $this->project('Purchase Project', $v->id, 'purchase_vendor');

        $this->ticket('TPV Ticket', $pt->id);
        $this->ticket('Purchase Ticket', $pp->id);

        $names = $this->ticketsFor($v->id);

        $this->assertContains('TPV Ticket', $names);
        $this->assertNotContains('Purchase Ticket', $names);
    }

    public function test_a_vendor_with_no_projects_gets_no_tickets(): void
    {
        [$a] = $this->twoVendorWorld();
        $empty = $this->vendor('EmptyCo');

        $this->assertCount(0, $this->ticketsFor($empty->id));
        // Sanity: the world is not simply empty.
        $this->assertCount(1, $this->ticketsFor($a->id));
    }

    public function test_ticket_tenant_isolation_holds(): void
    {
        $mine = $this->vendor('MineCo');
        $pm   = $this->project('My Project', $mine->id, 'tpv_vendor');
        $this->ticket('My Ticket', $pm->id);

        // Same vendor id value, other tenant.
        $pt = $this->project('Their Project', $mine->id, 'tpv_vendor', self::OTHER);
        $this->ticket('Their Ticket', $pt->id, self::OTHER);

        $this->assertNotContains('Their Ticket', $this->ticketsFor($mine->id));
        $this->assertNotContains(
            'My Ticket',
            $this->ticketsFor($mine->id, $this->user('admin', self::OTHER))
        );
    }

    /** The helpdesk bars the portal roles; the new filter must not open a door. */
    public function test_portal_roles_still_cannot_read_tickets(): void
    {
        [$a] = $this->twoVendorWorld();

        foreach (['third_party_vendor', 'vendor', 'client'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson('/api/helpdesk/tickets?vendor_id='.$a->id)->assertForbidden();
        }
    }

    /** Omitting the filter must leave the tenant-wide helpdesk queue untouched. */
    public function test_tenant_wide_ticket_listing_is_unchanged(): void
    {
        $this->twoVendorWorld();
        $this->ticket('Loose Ticket', null);

        Sanctum::actingAs($this->user('admin'));

        $names = collect($this->getJson('/api/helpdesk/tickets')->assertOk()->json('data'))->pluck('subject');

        foreach (['Ticket A', 'Ticket B', 'Loose Ticket'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    /* ── Expenses ────────────────────────────────────────────────── */

    public function test_expenses_from_the_vendors_projects_are_returned(): void
    {
        [$a] = $this->twoVendorWorld();

        $res = $this->expensesFor($a->id);

        $this->assertSame(['Expense A'], collect($res['rows'])->pluck('title')->all());
        $this->assertSame(100.0, (float) $res['total']);
    }

    public function test_another_vendors_expenses_are_never_returned(): void
    {
        [$a, $b] = $this->twoVendorWorld();

        $this->assertNotContains('Expense B', collect($this->expensesFor($a->id)['rows'])->pluck('title'));
        $this->assertNotContains('Expense A', collect($this->expensesFor($b->id)['rows'])->pluck('title'));
    }

    public function test_expenses_from_unrelated_projects_are_excluded(): void
    {
        $v  = $this->vendor('ExpCo');
        $p  = $this->project('Linked Project', $v->id, 'tpv_vendor');
        $up = $this->project('Internal Project', null, null);

        $this->expense('Vendor Expense', $p->id, 50);
        $this->expense('Internal Expense', $up->id, 70);

        $titles = collect($this->expensesFor($v->id)['rows'])->pluck('title');

        $this->assertContains('Vendor Expense', $titles);
        $this->assertNotContains('Internal Expense', $titles);
    }

    public function test_a_purchase_vendor_projects_expenses_are_excluded(): void
    {
        $v  = $this->vendor('SharedExpCo');
        $pt = $this->project('TPV Project', $v->id, 'tpv_vendor');
        $pp = $this->project('Purchase Project', $v->id, 'purchase_vendor');

        $this->expense('TPV Expense', $pt->id, 10);
        $this->expense('Purchase Expense', $pp->id, 20);

        $res = $this->expensesFor($v->id);

        $this->assertSame(['TPV Expense'], collect($res['rows'])->pluck('title')->all());
        $this->assertSame(10.0, (float) $res['total'], 'The total must not include another module\'s spend.');
    }

    public function test_a_vendor_with_no_projects_gets_no_expenses(): void
    {
        $this->twoVendorWorld();
        $empty = $this->vendor('EmptyCo');

        $res = $this->expensesFor($empty->id);

        $this->assertCount(0, $res['rows']);
        $this->assertSame(0.0, (float) $res['total']);
    }

    public function test_expense_tenant_isolation_holds(): void
    {
        $mine = $this->vendor('MineExpCo');
        $pm   = $this->project('My Project', $mine->id, 'tpv_vendor');
        $this->expense('My Expense', $pm->id, 500);

        $pt = $this->project('Their Project', $mine->id, 'tpv_vendor', self::OTHER);
        $this->expense('Their Expense', $pt->id, 900, self::OTHER);

        $this->assertNotContains('Their Expense', collect($this->expensesFor($mine->id)['rows'])->pluck('title'));

        $theirs = $this->expensesFor($mine->id, $this->user('admin', self::OTHER));
        $this->assertNotContains('My Expense', collect($theirs['rows'])->pluck('title'));
    }

    /** Non-admins reach only their own projects' expenses — inherited, not re-derived. */
    public function test_staff_only_see_expenses_of_projects_they_can_open(): void
    {
        $v     = $this->vendor('StaffExpCo');
        $staff = $this->user('staff');

        $mine = $this->project('Staff Project', $v->id, 'tpv_vendor');
        $mine->created_by = $staff->id;
        $mine->save();

        $other = $this->project('Other Project', $v->id, 'tpv_vendor');   // created by admin

        $this->expense('Visible Expense', $mine->id, 30);
        $this->expense('Hidden Expense', $other->id, 40);

        $res = $this->expensesFor($v->id, $staff);

        $this->assertSame(['Visible Expense'], collect($res['rows'])->pluck('title')->all());
        $this->assertSame(30.0, (float) $res['total'], 'The total must not leak a hidden project\'s amount.');
    }

    public function test_portal_roles_cannot_read_vendor_expenses(): void
    {
        [$a] = $this->twoVendorWorld();

        foreach (['third_party_vendor', 'vendor', 'client'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson('/api/projects/expenses?vendor_id='.$a->id)->assertForbidden();
        }
    }

    public function test_vendor_expenses_requires_a_vendor(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/projects/expenses')->assertStatus(422);
    }

    /** The per-project expense endpoint must behave exactly as before. */
    public function test_existing_per_project_expense_endpoint_is_unchanged(): void
    {
        $v = $this->vendor('UnchangedCo');
        $p = $this->project('Some Project', $v->id, 'tpv_vendor');
        $this->expense('Direct Expense', $p->id, 15);

        Sanctum::actingAs($this->user('admin'));

        $res = $this->getJson('/api/projects/'.$p->id.'/expenses')->assertOk()->json('data');

        $this->assertSame(['Direct Expense'], collect($res['rows'])->pluck('title')->all());
        $this->assertSame(15.0, (float) $res['total']);
    }
}
