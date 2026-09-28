<?php

namespace Tests\Feature\Portal;

use App\Models\Helpdesk\Ticket;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Purchase vendor can ask for help.
 *
 * The Purchase portal listed tickets and nothing more: no raise endpoint, no
 * detail, no reply, and its Tickets screen was mounted with `ticketWrite:
 * false`, which hid the Raise Ticket button and made every row unclickable. So
 * a Purchase vendor could see tickets raised about their projects and had no
 * way to open one, answer one, or ask for anything themselves. The TPV portal
 * has had all four since day one.
 *
 * The interesting part is identity. A TPV vendor signs in as a User, so its
 * ticket carries `created_by` and ownership is a comparison of user ids. A
 * PurchaseVendor is its own model and may have no User at all — so ownership
 * has to hold on the vendor's email as requester too, and a vendor with neither
 * must own nothing rather than everything. That last case is the one worth a
 * test: a `where` group that matches no condition matches every row.
 */
class PurchaseVendorRaisesTicketTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function vendor(string $company, ?string $email = null, bool $withUser = false): PurchaseVendor
    {
        $userId = null;
        if ($withUser) {
            $userId = User::create([
                'tenant_id' => self::TENANT, 'name' => $company, 'role' => 'vendor',
                'email' => 'u-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
            ])->id;
        }

        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $company,
            'purchase_vendor_code' => 'PV-'.Str::random(8),
            'status' => PurchaseVendorStatus::ACTIVE, 'portal_status' => 'active',
            'email' => $email ?? strtolower(Str::slug($company)).'@vendor.test',
            'user_id' => $userId,
        ]);
        $this->markOnboarded($vendor);

        return $vendor->fresh();
    }

    public function test_a_vendor_raises_a_ticket_and_then_sees_it(): void
    {
        $v = $this->vendor('Bolt Supplies');

        Sanctum::actingAs($v);
        $raised = $this->postJson('/api/portal/purchase/work-tickets', [
            'subject' => 'Portal will not accept my GST certificate',
            'body' => 'The upload fails at 90%. Attaching details.',
            'priority' => 'high',
        ])->assertCreated()->json();

        $this->assertDatabaseHas('tickets', [
            'id' => $raised['id'],
            'subject' => 'Portal will not accept my GST certificate',
            'source' => 'portal',
            'requester_email' => $v->email,
        ]);

        // The ticket must come back in the vendor's own list — it belongs to no
        // project, which is exactly what the old project-only query missed.
        $rows = $this->getJson('/api/portal/purchase/work-tickets')->assertOk()->json('data');
        $this->assertSame([$raised['id']], array_column($rows, 'id'));
    }

    public function test_a_vendor_opens_and_replies_to_its_own_ticket(): void
    {
        $v = $this->vendor('Bolt Supplies');

        Sanctum::actingAs($v);
        $id = $this->postJson('/api/portal/purchase/work-tickets', [
            'subject' => 'Access to the gate register', 'body' => 'Please advise.',
        ])->assertCreated()->json('id');

        $this->postJson("/api/portal/purchase/work-tickets/{$id}/reply", [
            'message' => 'Adding our site contact: Rita, 9am-5pm.',
        ])->assertCreated();

        $body = $this->getJson("/api/portal/purchase/work-tickets/{$id}")->assertOk()->json();

        $this->assertSame('Access to the gate register', $body['subject']);
        $this->assertCount(1, $body['replies']);
        $this->assertSame('You', $body['replies'][0]['author'], 'the vendor sees their own reply as theirs');
        $this->assertTrue($body['replies'][0]['mine']);
    }

    public function test_one_vendor_cannot_read_or_answer_anothers_ticket(): void
    {
        $mine = $this->vendor('Bolt Supplies');
        $rival = $this->vendor('Rival Ltd');

        Sanctum::actingAs($mine);
        $id = $this->postJson('/api/portal/purchase/work-tickets', [
            'subject' => 'Commercially sensitive', 'body' => 'Our rates are wrong.',
        ])->assertCreated()->json('id');

        Sanctum::actingAs($rival);
        $this->getJson("/api/portal/purchase/work-tickets/{$id}")->assertNotFound();
        $this->postJson("/api/portal/purchase/work-tickets/{$id}/reply", ['message' => 'hello'])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/portal/purchase/work-tickets')->assertOk()->json('data'));
    }

    /**
     * A vendor with no email and no user owns nothing — not everything.
     *
     * The ownership filter is a group of OR clauses. If none of them apply, an
     * empty group matches every row, which would have shown one vendor the
     * whole tenant's helpdesk. This is the assertion that keeps that shut.
     */
    public function test_a_vendor_with_no_identity_sees_no_tickets(): void
    {
        $anon = $this->vendor('Anonymous Ltd', email: '');
        $anon->forceFill(['email' => null])->save();

        Ticket::create([
            'tenant_id' => self::TENANT, 'subject' => 'Somebody elses problem',
            'description' => 'x', 'status' => 'open', 'priority' => 'medium', 'source' => 'portal',
        ]);

        Sanctum::actingAs($anon);
        $this->assertSame([], $this->getJson('/api/portal/purchase/work-tickets')->assertOk()->json('data'));

        // And they are told why they cannot raise one, rather than failing later.
        $this->postJson('/api/portal/purchase/work-tickets', [
            'subject' => 'Help', 'body' => 'Please.',
        ])->assertStatus(422);
    }

    public function test_a_ticket_needs_a_subject_and_a_message(): void
    {
        $v = $this->vendor('Bolt Supplies');

        Sanctum::actingAs($v);
        $this->postJson('/api/portal/purchase/work-tickets', ['subject' => '', 'body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'body']);
    }
}
