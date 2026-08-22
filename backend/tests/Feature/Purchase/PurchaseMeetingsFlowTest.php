<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseNcr;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase Meetings (Sangoe TPV §9) — the WHOLE flow, end to end, through the real
 * HTTP API as authenticated actors. Where the tinker checks proved the service
 * methods in isolation, this proves the doc's journey works step by step with
 * routing, request validation, roles and tenant isolation in the loop.
 *
 * Doc-flow map (§9) asserted here:
 *   schedule → (publish refused: not completed) → complete → attach MOM →
 *   (publish refused: not approved) → submit for approval →
 *   organizer approves → chairperson approves → publish for acknowledgement.
 * Plus the two governance rules the module must enforce:
 *   Rule 11 (every action has an owner) and Rule 12 (no closure without evidence),
 *   and the issue → NCR escalation chain, and cross-tenant isolation.
 */
class PurchaseMeetingsFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('purchase_kickoff_docs');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role = 'admin', int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name = 'FlowCo', int $tenant = self::TENANT): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower(Str::random(5)).'@test.local',
            'status' => 'Draft', 'portal_status' => 'active',
        ]);
    }

    /** Schedule a completed meeting and return its id (helper for later steps). */
    private function scheduleAndComplete(): int
    {
        $vendor = $this->vendor();
        $id = $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id,
            'title'              => 'Kickoff — '.$vendor->company_name,
            'meeting_type'       => 'kickoff',
            'scheduled_at'       => now()->subDay()->toDateTimeString(),
        ])->assertCreated()->json('id');

        $this->postJson("/api/purchase/kickoff/{$id}/transition", ['status' => 'Completed', 'minutes' => 'Discussed scope.'])
            ->assertOk()->assertJsonPath('status', 'Completed');

        return $id;
    }

    /** The core §9 journey: schedule → complete → MOM approval chain → publish. */
    public function test_mom_approval_lifecycle_end_to_end(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $vendor = $this->vendor();

        // 1. Schedule → Scheduled, minutes Draft.
        $create = $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $vendor->id,
            'title'              => 'Kickoff meeting',
            'meeting_type'       => 'kickoff',
            'scheduled_at'       => now()->subDay()->toDateTimeString(),
        ])->assertCreated();
        $id = $create->json('id');
        $create->assertJsonPath('status', 'Scheduled')->assertJsonPath('mom_status', 'Draft');

        // 2. Publish is refused before the meeting is even completed.
        $this->postJson("/api/purchase/kickoff/{$id}/publish")->assertStatus(422);

        // 3. Complete the meeting.
        $this->postJson("/api/purchase/kickoff/{$id}/transition", ['status' => 'Completed', 'minutes' => 'Notes.'])
            ->assertOk()->assertJsonPath('status', 'Completed');

        // 4. Attach a MOM document (avoids depending on PDF rendering).
        $this->postJson("/api/purchase/kickoff/{$id}/mom", [
            'file' => UploadedFile::fake()->create('mom.pdf', 40, 'application/pdf'),
        ])->assertOk();

        // 5. Even completed with a MOM, publish is refused until it is APPROVED.
        $this->postJson("/api/purchase/kickoff/{$id}/publish")->assertStatus(422);

        // 6. Submit for approval → Pending Organizer.
        $this->postJson("/api/purchase/kickoff/{$id}/mom/submit")
            ->assertOk()->assertJsonPath('mom_status', 'Pending_Approval');

        // 7. Organizer approves → Pending Chairperson.
        $this->postJson("/api/purchase/kickoff/{$id}/mom/decide", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('mom_status', 'Pending_Chairperson');

        // 8. Chairperson approves → Approved.
        $this->postJson("/api/purchase/kickoff/{$id}/mom/decide", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('mom_status', 'Approved');

        // 9. Now publish succeeds → Distributed.
        $this->postJson("/api/purchase/kickoff/{$id}/publish")
            ->assertOk()->assertJsonPath('mom_status', 'Distributed');
    }

    /** A returned MOM needs a reason, and returning resets it to Draft. */
    public function test_mom_return_requires_a_reason(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $id = $this->scheduleAndComplete();
        $this->postJson("/api/purchase/kickoff/{$id}/mom", ['file' => UploadedFile::fake()->create('m.pdf', 10, 'application/pdf')])->assertOk();
        $this->postJson("/api/purchase/kickoff/{$id}/mom/submit")->assertOk();

        // Return with no note is refused.
        $this->postJson("/api/purchase/kickoff/{$id}/mom/decide", ['decision' => 'return'])->assertStatus(422);
        // Return with a note sends it back to Draft.
        $this->postJson("/api/purchase/kickoff/{$id}/mom/decide", ['decision' => 'return', 'note' => 'Fix owners'])
            ->assertOk()->assertJsonPath('mom_status', 'Draft');
    }

    /** Rule 11 (owner required) + Rule 12 (no closure without evidence), via HTTP. */
    public function test_action_owner_and_evidence_rules(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $id = $this->scheduleAndComplete();

        // Rule 11: no owner → refused.
        $this->postJson("/api/purchase/kickoff/{$id}/actions", ['description' => 'Do the thing'])
            ->assertStatus(422);

        // With an owner → created Open.
        $actionId = $this->postJson("/api/purchase/kickoff/{$id}/actions", [
            'description' => 'Send signed NDA', 'responsible_names' => 'Alice',
        ])->assertCreated()->assertJsonPath('status', 'Open')->json('id');

        // Advance to a verifiable state.
        $this->postJson("/api/purchase/kickoff/{$id}/actions/{$actionId}/progress", ['status' => 'Pending_Verification'])->assertOk();

        // Rule 12: close with no evidence/note → refused.
        $this->postJson("/api/purchase/kickoff/{$id}/actions/{$actionId}/progress", ['status' => 'Closed'])
            ->assertStatus(422);

        // Close with a verification note → Closed.
        $this->postJson("/api/purchase/kickoff/{$id}/actions/{$actionId}/progress", ['status' => 'Closed', 'verification_note' => 'NDA on file'])
            ->assertOk()->assertJsonPath('status', 'Closed');
    }

    /** Issue → NCR escalation actually creates a Purchase NCR and links back. */
    public function test_issue_escalates_to_ncr(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $id = $this->scheduleAndComplete();

        $issueId = $this->postJson("/api/purchase/kickoff/{$id}/issues", [
            'title' => 'Late samples', 'severity' => 'High', 'category' => 'Schedule',
        ])->assertCreated()->json('id');

        $before = PurchaseNcr::where('tenant_id', self::TENANT)->count();

        $this->postJson("/api/purchase/kickoff/{$id}/issues/{$issueId}/convert", ['target' => 'ncr'])
            ->assertOk()->assertJsonPath('converted_to', 'NCR');

        $this->assertSame($before + 1, PurchaseNcr::where('tenant_id', self::TENANT)->count());

        // Idempotent — a second conversion is refused.
        $this->postJson("/api/purchase/kickoff/{$id}/issues/{$issueId}/convert", ['target' => 'capa'])
            ->assertStatus(422);
    }

    /** A meeting in one tenant is invisible (404) to a user in another. */
    public function test_meeting_is_tenant_isolated(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $id = $this->scheduleAndComplete();

        // Second tenant + user.
        (new Tenant())->forceFill(['id' => 2, 'name' => 'Tenant 2', 'slug' => 't-2', 'subdomain' => 't2', 'status' => 'active'])->save();
        Sanctum::actingAs($this->user('admin', 2));

        $this->getJson("/api/purchase/kickoff/{$id}")->assertNotFound();
        $this->postJson("/api/purchase/kickoff/{$id}/publish")->assertNotFound();
    }
}
