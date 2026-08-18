<?php

namespace Tests\Feature\Tpv;

use App\Models\Shared\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The vendor Notes and Reminders tabs.
 *
 * Both ride SHARED polymorphic tables rather than vendor-specific ones, which is
 * what these pin down:
 *
 *  - the subject comes from the ROUTE, never the payload, so a note or reminder
 *    cannot be retargeted at another record;
 *  - an id belonging to a different vendor is a 404, not an edit;
 *  - the routes sit in the TPV admin group. /api/sales/reminders carries no role
 *    gate, so vendor follow-ups were deliberately NOT mounted there — a vendor
 *    portal login must not reach them.
 */
class VendorNotesRemindersTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name, ?User $owner = null): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(6).'@vendor.local',
            'status' => VendorStatus::ACTIVE, 'user_id' => $owner?->id,
        ]);
    }

    /* ── Reminders ────────────────────────────────────────────────────── */

    public function test_staff_can_raise_and_complete_a_vendor_reminder(): void
    {
        $vendor = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $id = $this->postJson("/api/tpv/vendors/{$vendor->id}/reminders", [
            'type' => 'call', 'title' => 'Chase insurance certificate',
            'due_at' => now()->addDay()->toDateTimeString(), 'priority' => 'high',
        ])->assertStatus(201)->json('id');

        // Stored against the vendor, from the route — not from anything posted.
        $this->getJson("/api/tpv/vendors/{$vendor->id}/reminders")
            ->assertOk()
            ->assertJsonPath('0.id', $id)
            ->assertJsonPath('0.title', 'Chase insurance certificate');

        $this->postJson("/api/tpv/vendors/{$vendor->id}/reminders/{$id}/complete", ['outcome' => 'Received'])
            ->assertOk();

        $this->assertNotNull(\App\Models\Sales\Reminder::find($id)->completed_at);
    }

    public function test_a_reminder_cannot_be_reached_through_another_vendor(): void
    {
        $alpha = $this->vendor('AlphaCo');
        $beta  = $this->vendor('BetaCo');

        Sanctum::actingAs($this->user('staff'));

        $id = $this->postJson("/api/tpv/vendors/{$alpha->id}/reminders", [
            'type' => 'call', 'title' => 'Alpha only', 'due_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(201)->json('id');

        // The vendor in the URL is load-bearing, not decoration.
        $this->postJson("/api/tpv/vendors/{$beta->id}/reminders/{$id}/complete", [])->assertStatus(404);
        $this->deleteJson("/api/tpv/vendors/{$beta->id}/reminders/{$id}")->assertStatus(404);

        $this->assertNull(\App\Models\Sales\Reminder::find($id)->completed_at);
        $this->getJson("/api/tpv/vendors/{$beta->id}/reminders")->assertOk()->assertJsonCount(0);
    }

    public function test_a_vendor_login_cannot_reach_vendor_reminders(): void
    {
        $user   = $this->user('third_party_vendor');
        $vendor = $this->vendor('AlphaCo', $user);

        Sanctum::actingAs($user);

        $this->getJson("/api/tpv/vendors/{$vendor->id}/reminders")->assertStatus(403);
        $this->postJson("/api/tpv/vendors/{$vendor->id}/reminders", [
            'type' => 'call', 'title' => 'x', 'due_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(403);
    }

    /* ── Notes ────────────────────────────────────────────────────────── */

    public function test_staff_can_write_edit_and_delete_a_vendor_note(): void
    {
        $vendor = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $id = $this->postJson("/api/tpv/vendors/{$vendor->id}/notes", [
            'title' => 'Prefers morning deliveries', 'content' => '<p>Site manager asked.</p>',
        ])->assertStatus(201)->json('id');

        $this->getJson("/api/tpv/vendors/{$vendor->id}/notes")->assertOk()->assertJsonCount(1);

        $this->putJson("/api/tpv/vendors/{$vendor->id}/notes/{$id}", [
            'title' => 'Prefers afternoon deliveries', 'content' => '<p>Changed.</p>',
        ])->assertOk()->assertJsonPath('title', 'Prefers afternoon deliveries');

        $this->deleteJson("/api/tpv/vendors/{$vendor->id}/notes/{$id}")->assertOk();
        $this->assertNull(Note::find($id));
    }

    public function test_a_note_cannot_be_reached_through_another_vendor(): void
    {
        $alpha = $this->vendor('AlphaCo');
        $beta  = $this->vendor('BetaCo');

        Sanctum::actingAs($this->user('staff'));

        $id = $this->postJson("/api/tpv/vendors/{$alpha->id}/notes", ['title' => 'Alpha only'])
            ->assertStatus(201)->json('id');

        $this->putJson("/api/tpv/vendors/{$beta->id}/notes/{$id}", ['title' => 'Hijacked'])->assertStatus(404);
        $this->deleteJson("/api/tpv/vendors/{$beta->id}/notes/{$id}")->assertStatus(404);

        $this->assertSame('Alpha only', Note::find($id)->title);
        $this->getJson("/api/tpv/vendors/{$beta->id}/notes")->assertOk()->assertJsonCount(0);
    }

    public function test_note_content_is_sanitised(): void
    {
        // Notes are rich text, so the stored HTML must not carry script.
        $vendor = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $id = $this->postJson("/api/tpv/vendors/{$vendor->id}/notes", [
            'title' => 'XSS', 'content' => '<p>ok</p><script>alert(1)</script>',
        ])->assertStatus(201)->json('id');

        $this->assertStringNotContainsString('<script', (string) Note::find($id)->content);
    }

    public function test_a_vendor_login_cannot_reach_vendor_notes(): void
    {
        $user   = $this->user('third_party_vendor');
        $vendor = $this->vendor('AlphaCo', $user);

        Sanctum::actingAs($user);

        $this->getJson("/api/tpv/vendors/{$vendor->id}/notes")->assertStatus(403);
        $this->postJson("/api/tpv/vendors/{$vendor->id}/notes", ['title' => 'x'])->assertStatus(403);
    }
}
