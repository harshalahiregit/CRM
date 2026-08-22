<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseApprovalRequest;
use App\Models\Purchase\PurchaseKickoffParticipant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Meeting.docx completeness — the features added to close the gaps, exercised
 * end-to-end through the real HTTP API: agenda builder + templates + copy-previous
 * (§3/§4), previous-MOM carry-forward (§11), the meeting dashboard (§14), the
 * §2 creation fields + auto Meeting-No, 6-state attendance (§6), and issue →
 * Approval escalation (§10).
 */
class PurchaseMeetingsExtendedFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        Sanctum::actingAs($this->user());
    }

    private function user(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@test.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Ext '.Str::random(4),
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower(Str::random(5)).'@test.local', 'status' => 'Draft', 'portal_status' => 'active',
        ]);
    }

    private function schedule(array $extra = []): array
    {
        return $this->postJson('/api/purchase/kickoff', array_merge([
            'purchase_vendor_id' => $this->vendor()->id, 'title' => 'M '.Str::random(4),
            'meeting_type' => 'kickoff', 'scheduled_at' => now()->subDay()->toDateTimeString(),
        ], $extra))->assertCreated()->json();
    }

    /** §2 creation fields persist and a Meeting-No is auto-assigned. */
    public function test_creation_fields_and_meeting_no(): void
    {
        $m = $this->schedule([
            'priority' => 'High', 'confidentiality' => 'Confidential', 'chairperson' => 'Ms Chair',
            'coordinator' => 'Mr Coord', 'organizer' => 'Org', 'department' => 'Procurement',
            'client_name' => 'Acme', 'mode' => 'hybrid', 'end_at' => now()->toDateTimeString(),
        ]);
        $this->assertMatchesRegularExpression('/^MTG-\d{4}-\d{4}$/', $m['meeting_no']);
        $this->assertSame('High', $m['priority']);
        $this->assertSame('hybrid', $m['mode']);
        $this->assertSame('Ms Chair', $m['chairperson']);
    }

    /** §3/§4 agenda builder: manual add, load-from-template, copy-from-previous. */
    public function test_agenda_builder_template_and_copy(): void
    {
        $vendorId = $this->vendor()->id;
        $m1 = $this->postJson('/api/purchase/kickoff', ['purchase_vendor_id' => $vendorId, 'title' => 'Prev', 'meeting_type' => 'kickoff', 'scheduled_at' => now()->subDays(3)->toDateTimeString()])->json();

        $this->postJson("/api/purchase/kickoff/{$m1['id']}/agenda", ['item' => 'Safety review', 'duration_minutes' => 15, 'priority' => 'High'])
            ->assertCreated()->assertJsonPath('item', 'Safety review');

        // load-template appends the kickoff template rows
        $afterTemplate = $this->postJson("/api/purchase/kickoff/{$m1['id']}/agenda/load-template")->assertOk()->json();
        $this->assertGreaterThan(1, count($afterTemplate));

        // a second meeting for the same vendor can copy the previous agenda
        $m2 = $this->postJson('/api/purchase/kickoff', ['purchase_vendor_id' => $vendorId, 'title' => 'New', 'meeting_type' => 'kickoff', 'scheduled_at' => now()->toDateTimeString()])->json();
        $copied = $this->postJson("/api/purchase/kickoff/{$m2['id']}/agenda/copy-previous")->assertOk()->json();
        $this->assertSame(count($afterTemplate), count($copied));
    }

    /** §11 carry-forward: previous summary + carrying open actions/issues. */
    public function test_previous_summary_and_carry_forward(): void
    {
        $vendorId = $this->vendor()->id;
        $m1 = $this->postJson('/api/purchase/kickoff', ['purchase_vendor_id' => $vendorId, 'title' => 'P', 'meeting_type' => 'kickoff', 'scheduled_at' => now()->subDays(5)->toDateTimeString()])->json();
        $this->postJson("/api/purchase/kickoff/{$m1['id']}/actions", ['description' => 'Fix', 'responsible_names' => 'A'])->assertCreated();
        $this->postJson("/api/purchase/kickoff/{$m1['id']}/issues", ['title' => 'Gap', 'severity' => 'High'])->assertCreated();

        $m2 = $this->postJson('/api/purchase/kickoff', ['purchase_vendor_id' => $vendorId, 'title' => 'N', 'meeting_type' => 'kickoff', 'scheduled_at' => now()->toDateTimeString()])->json();

        $this->getJson("/api/purchase/kickoff/{$m2['id']}/previous-summary")
            ->assertOk()->assertJsonPath('actions.open', 1)->assertJsonPath('issues.open', 1);

        $this->postJson("/api/purchase/kickoff/{$m2['id']}/carry-forward")
            ->assertOk()->assertJsonPath('actions', 1)->assertJsonPath('issues', 1);

        // the carried items now live on m2
        $this->assertCount(1, $this->getJson("/api/purchase/kickoff/{$m2['id']}/actions")->json());
        $this->assertCount(1, $this->getJson("/api/purchase/kickoff/{$m2['id']}/issues")->json());
    }

    /** §14 dashboard exposes the governance metrics. */
    public function test_dashboard_metrics(): void
    {
        $this->schedule();
        $d = $this->getJson('/api/purchase/kickoff/dashboard')->assertOk()->json();
        foreach (['total', 'today', 'upcoming', 'pending_mom', 'overdue_mom', 'open_actions', 'overdue_actions', 'closure_rate', 'by_type'] as $k) {
            $this->assertArrayHasKey($k, $d, "dashboard missing {$k}");
        }
    }

    /** §6 six-state attendance persists and projects onto the boolean. */
    public function test_six_state_attendance(): void
    {
        $m = $this->schedule(['participants' => [['name' => 'Bob', 'side' => 'internal', 'designation' => 'HSE', 'phone' => '123']]]);
        $pid = PurchaseKickoffParticipant::where('purchase_kickoff_meeting_id', $m['id'])->value('id');

        $this->patchJson("/api/purchase/kickoff/{$m['id']}/attendance", ['rows' => [['id' => $pid, 'attendance_status' => 'Late']]])->assertOk();

        $p = PurchaseKickoffParticipant::find($pid);
        $this->assertSame('Late', $p->attendance_status);
        $this->assertTrue((bool) $p->attended);      // Late counts as attended
        $this->assertSame('internal', $p->side);
        $this->assertSame('HSE', $p->designation);
    }

    /** §10 issue → Approval creates a central Approval-register entry. */
    public function test_issue_converts_to_approval(): void
    {
        $m = $this->schedule();
        $iid = $this->postJson("/api/purchase/kickoff/{$m['id']}/issues", ['title' => 'Needs sign-off', 'severity' => 'Critical'])->assertCreated()->json('id');

        $before = PurchaseApprovalRequest::where('tenant_id', self::TENANT)->count();
        $this->postJson("/api/purchase/kickoff/{$m['id']}/issues/{$iid}/convert", ['target' => 'approval'])
            ->assertOk()->assertJsonPath('converted_to', 'Approval');
        $this->assertSame($before + 1, PurchaseApprovalRequest::where('tenant_id', self::TENANT)->count());
    }
}
