<?php

namespace Tests\Feature\Tpv;

use App\Models\Helpdesk\Ticket;
use App\Models\Project\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Raising a ticket from the TPV vendor screen.
 *
 * The screen offers only that vendor's projects, but a picker is a convenience,
 * not a boundary. tickets.project_id is the ONLY thing tying a ticket to a
 * vendor, so these tests drive POST /helpdesk/tickets directly, ignoring the
 * form, and check what the SERVER does with a project id it was handed.
 *
 * The guard already lived in HelpdeskService, which re-checks tenant ownership
 * and drops a dangling reference instead of failing the ticket. Nothing here
 * changed that; these tests pin it so the extraction of the form — and any later
 * change to it — cannot quietly move the boundary.
 */
class TpvTicketCreationTest extends TestCase
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

    private function project(string $name, ?int $vendorId, int $tenantId = self::TENANT): Project
    {
        $p = (new Project())->forceFill([
            'tenant_id'  => $tenantId, 'name' => $name,
            'status'     => 'in_progress', 'progress' => 0, 'start_date' => '2026-01-01',
            'created_by' => $this->creator($tenantId)->id,
            'vendor_id'  => $vendorId, 'link_type' => $vendorId ? 'tpv_vendor' : null,
        ]);
        $p->save();

        return $p;
    }

    private function raise(array $payload)
    {
        return $this->postJson('/api/helpdesk/tickets', $payload + [
            'subject'  => 'Scaffold collapsed on level 3',
            'priority' => 'medium',
            'source'   => 'tpv',
        ]);
    }

    /** The happy path: a ticket on this vendor's project lands on its tab. */
    public function test_a_ticket_can_be_raised_against_the_vendors_own_project(): void
    {
        $v = $this->vendor('AlphaCo');
        $p = $this->project('Plant Shutdown', $v->id);

        Sanctum::actingAs($this->user('admin'));

        $this->raise(['project_id' => $p->id])->assertCreated();

        $subjects = collect($this->getJson('/api/helpdesk/tickets?vendor_id='.$v->id)->assertOk()->json('data'))
            ->pluck('subject');

        $this->assertContains('Scaffold collapsed on level 3', $subjects);
    }

    /**
     * A ticket raised on vendor B's project must never appear under vendor A —
     * the project link is the whole of the relationship, so mis-picking it would
     * silently file the ticket against the wrong company.
     */
    public function test_a_ticket_on_another_vendors_project_does_not_reach_this_vendor(): void
    {
        $a = $this->vendor('VendorA');
        $b = $this->vendor('VendorB');
        $this->project('Project A', $a->id);
        $pb = $this->project('Project B', $b->id);

        Sanctum::actingAs($this->user('admin'));

        $this->raise(['project_id' => $pb->id])->assertCreated();

        $this->assertCount(0, $this->getJson('/api/helpdesk/tickets?vendor_id='.$a->id)->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/helpdesk/tickets?vendor_id='.$b->id)->assertOk()->json('data'));
    }

    /**
     * The boundary a picker cannot enforce.
     *
     * HelpdeskService re-checks tenant ownership and DROPS a dangling reference
     * rather than failing the ticket — a deliberate choice, because the link is a
     * convenience for the "Raise Ticket" buttons, not a requirement. So the
     * property to hold is not "the request is rejected" but the stronger one:
     * another tenant's project id is never stored, and the ticket never surfaces
     * on any vendor's tab.
     */
    public function test_a_project_from_another_tenant_is_never_linked(): void
    {
        $theirVendor = $this->vendor('TheirCo', self::OTHER);
        $theirs      = $this->project('Their Project', $theirVendor->id, self::OTHER);

        Sanctum::actingAs($this->user('admin'));   // tenant 1

        $res = $this->raise(['project_id' => $theirs->id])->assertCreated();
        $id  = ($res->json('data') ?? $res->json())['id'];

        $this->assertNull(
            Ticket::find($id)->project_id,
            "A foreign tenant's project must never be stored on a ticket."
        );

        // And it reaches nobody's vendor tab, in either tenant.
        $this->assertCount(0, $this->getJson('/api/helpdesk/tickets?vendor_id='.$theirVendor->id)->assertOk()->json('data'));
    }

    /** A project id that exists nowhere is dropped, not stored. */
    public function test_an_unknown_project_is_never_linked(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $res = $this->raise(['project_id' => 999999])->assertCreated();
        $id  = ($res->json('data') ?? $res->json())['id'];

        $this->assertNull(Ticket::find($id)->project_id);
    }

    /** The plain helpdesk path — no project at all — must still work. */
    public function test_a_ticket_without_a_project_is_still_accepted(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->postJson('/api/helpdesk/tickets', [
            'subject' => 'Printer is jammed', 'priority' => 'low',
        ])->assertCreated();
    }

    /** Existing helpdesk creation is unchanged for every other field. */
    public function test_existing_helpdesk_ticket_creation_is_unchanged(): void
    {
        $agent = $this->user('staff');

        Sanctum::actingAs($this->user('admin'));

        $res = $this->postJson('/api/helpdesk/tickets', [
            'subject'         => 'Laptop will not boot',
            'description'     => 'Blue screen on start-up.',
            'priority'        => 'high',
            'status'          => 'open',
            'assigned_to'     => $agent->id,
            'requester_name'  => 'Ravi',
            'requester_email' => 'ravi@example.com',
        ])->assertCreated();

        $body = $res->json('data') ?? $res->json();

        $this->assertSame('Laptop will not boot', $body['subject']);
        $this->assertSame($agent->id, (int) $body['assigned_to']);
        $this->assertNull($body['project_id'] ?? null);
    }

    /** The portal roles never reach ticket creation, project or not. */
    public function test_portal_roles_cannot_raise_tickets(): void
    {
        $p = $this->project('Guarded', $this->vendor('DenyCo')->id);

        foreach (['third_party_vendor', 'vendor', 'client'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->raise(['project_id' => $p->id])->assertForbidden();
        }
    }
}
