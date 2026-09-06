<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrDemoRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Inbound demo enquiries, natively rather than through a proxy to SangoeTrack. */
class DemoRequestTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'demo-t', 'status' => 'active']);
    }

    private function user(string $email, string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
        ]);
    }

    public function test_a_request_can_be_logged_and_read_back_with_every_field(): void
    {
        Sanctum::actingAs($this->user('admin@example.test'));

        $this->postJson('/api/hr/demo-requests', [
            'name' => 'Priya Sharma', 'company_name' => 'Acme Ltd', 'email' => 'priya@acme.test',
            'phone' => '9876543210', 'address' => 'Pune', 'num_employees' => 120,
            'message' => 'Interested in attendance and payroll.', 'source' => 'website',
        ])->assertCreated();

        $r = $this->getJson('/api/hr/demo-requests')->assertOk()->json('data.0');

        foreach (['name', 'company_name', 'email', 'phone', 'address', 'num_employees', 'message', 'source'] as $f) {
            $this->assertNotNull($r[$f], "{$f} was accepted and did not come back.");
        }
        $this->assertSame('new', $r['status']);
    }

    public function test_it_can_be_worked_on(): void
    {
        $admin = $this->user('admin@example.test');
        Sanctum::actingAs($admin);

        $this->postJson('/api/hr/demo-requests', ['name' => 'Priya'])->assertCreated();
        $row = HrDemoRequest::firstOrFail();

        $this->putJson("/api/hr/demo-requests/{$row->id}", [
            'status' => 'scheduled', 'demo_at' => '2026-03-10 15:00:00',
            'notes' => 'Called; wants a walkthrough.', 'assigned_to' => $admin->id,
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');

        $row->refresh();
        $this->assertSame($admin->id, $row->assigned_to);
        $this->assertSame($admin->id, $row->updated_by);
    }

    /** What the enquirer said is a record; staff notes are separate. */
    public function test_the_enquirers_message_cannot_be_rewritten(): void
    {
        Sanctum::actingAs($this->user('admin@example.test'));

        $this->postJson('/api/hr/demo-requests', ['name' => 'Priya', 'message' => 'Original words.'])->assertCreated();
        $row = HrDemoRequest::firstOrFail();

        $this->putJson("/api/hr/demo-requests/{$row->id}", ['message' => 'Rewritten.', 'notes' => 'Staff note.'])->assertOk();

        $row->refresh();
        $this->assertSame('Original words.', $row->message);
        $this->assertSame('Staff note.', $row->notes);
    }

    /** An enquiry nobody has claimed must not be invisible to everybody. */
    public function test_unclaimed_requests_are_visible_and_claimed_on_first_edit(): void
    {
        HrDemoRequest::create(['name' => 'Walk-in', 'status' => 'new']);
        $admin = $this->user('admin@example.test');

        Sanctum::actingAs($admin);
        $this->getJson('/api/hr/demo-requests')->assertOk()->assertJsonCount(1, 'data');

        $row = HrDemoRequest::firstOrFail();
        $this->putJson("/api/hr/demo-requests/{$row->id}", ['status' => 'contacted'])->assertOk();

        $this->assertSame($this->tenant()->id, $row->fresh()->tenant_id);
    }

    public function test_another_tenants_claimed_request_is_not_visible(): void
    {
        $other = Tenant::create(['name' => 'O', 'slug' => 'demo-o', 'status' => 'active']);
        HrDemoRequest::create(['tenant_id' => $other->id, 'name' => 'Theirs', 'status' => 'new']);

        Sanctum::actingAs($this->user('admin@example.test'));
        $this->getJson('/api/hr/demo-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_is_gated(): void
    {
        Sanctum::actingAs($this->user('nobody@example.test', 'staff'));
        $this->getJson('/api/hr/demo-requests')->assertStatus(403);
        $this->postJson('/api/hr/demo-requests', ['name' => 'X'])->assertStatus(403);
    }

    public function test_bad_input_is_refused(): void
    {
        Sanctum::actingAs($this->user('admin@example.test'));

        $this->postJson('/api/hr/demo-requests', [])->assertStatus(422);
        $this->postJson('/api/hr/demo-requests', ['name' => 'X', 'email' => 'nope'])->assertStatus(422);
        $this->postJson('/api/hr/demo-requests', ['name' => 'X', 'num_employees' => -3])->assertStatus(422);
    }
}
