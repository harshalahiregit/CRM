<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Models\Purchase\PurchaseMomDecision;
use App\Models\Purchase\PurchaseMomIssue;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Purchase cross-meeting registers.
 *
 * Decisions, issues and actions were only readable inside the one meeting that
 * produced them, so an action raised in March and forgotten had nowhere to
 * resurface. These cover the part that makes it visible — and the rules that are
 * easy to get subtly wrong:
 *
 *   1. a register reads ACROSS meetings, so an item must appear even when its
 *      own meeting is not the one being looked at;
 *   2. the action backlog defaults to OPEN, because a register that defaulted
 *      to everything would bury this week's overdue item under last year's
 *      closed ones;
 *   3. every row carries the meeting and vendor it came from, or the register
 *      is a list of sentences with no way back to their source;
 *   4. tenants never see each other's items.
 */
class PurchaseMeetingRegistersTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER = 999;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, self::OTHER] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'T'.$id, 'slug' => 't'.$id,
                'subdomain' => 't'.$id, 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role = 'admin', int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name, int $tenant = self::TENANT): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower(Str::random(5)).'@test.local',
            'status' => 'Draft', 'portal_status' => 'active',
        ]);
    }

    private function meeting(PurchaseVendor $v, string $title, int $tenant = self::TENANT): PurchaseKickoffMeeting
    {
        return PurchaseKickoffMeeting::create([
            'tenant_id' => $tenant, 'purchase_vendor_id' => $v->id,
            'title' => $title, 'meeting_type' => 'kickoff',
            'status' => 'Completed', 'scheduled_at' => now()->subDays(3),
            'meeting_no' => 'PM-'.strtoupper(Str::random(5)),
        ]);
    }

    private function action(PurchaseKickoffMeeting $m, string $desc, string $status, ?string $due = null): PurchaseMomActionItem
    {
        return PurchaseMomActionItem::create([
            'tenant_id' => $m->tenant_id, 'purchase_kickoff_meeting_id' => $m->id,
            'action_ref' => 'A-'.strtoupper(Str::random(5)), 'description' => $desc,
            'responsible_names' => 'Sam Owner', 'status' => $status,
            'priority' => 'High', 'target_date' => $due,
        ]);
    }

    /**
     * The whole point: items from DIFFERENT meetings come back in one list.
     * Reading them one meeting at a time is what the register replaces.
     */
    public function test_action_register_reads_across_meetings(): void
    {
        Sanctum::actingAs($this->user());

        $vendorA = $this->vendor('AlphaCo');
        $vendorB = $this->vendor('BetaCo');
        $this->action($this->meeting($vendorA, 'March review'), 'Fix the scaffold tags', 'Open');
        $this->action($this->meeting($vendorB, 'April review'), 'Re-issue the permits', 'In_Progress');

        $rows = $this->getJson('/api/purchase/kickoff/registers/actions')->assertOk()->json();

        $this->assertCount(2, $rows, 'the register did not read across both meetings');

        $descs = array_column($rows, 'description');
        $this->assertContains('Fix the scaffold tags', $descs);
        $this->assertContains('Re-issue the permits', $descs);

        // Every row must carry a way back to where it came from.
        $vendors = array_column($rows, 'vendor');
        sort($vendors);
        $this->assertSame(['AlphaCo', 'BetaCo'], $vendors);
        foreach ($rows as $r) {
            $this->assertNotNull($r['meeting_id'], 'row has no meeting to click through to');
            $this->assertNotNull($r['meeting_title']);
        }
    }

    /**
     * The backlog defaults to open. status=all is the escape hatch — without the
     * default, the nav's "Open Action Items" would be a full history.
     */
    public function test_action_register_defaults_to_open_and_all_widens_it(): void
    {
        Sanctum::actingAs($this->user());

        $m = $this->meeting($this->vendor('DefaultCo'), 'Weekly');
        $this->action($m, 'Still outstanding', 'Open');
        $this->action($m, 'Long since done', 'Closed');

        $open = $this->getJson('/api/purchase/kickoff/registers/actions')->assertOk()->json();
        $this->assertSame(['Still outstanding'], array_column($open, 'description'));

        $all = $this->getJson('/api/purchase/kickoff/registers/actions?status=all')->assertOk()->json();
        $this->assertCount(2, $all);

        // Overdue is a narrower slice of open, not a separate status column.
        $this->action($m, 'Slipped last week', 'Open', now()->subWeek()->toDateString());
        $overdue = $this->getJson('/api/purchase/kickoff/registers/actions?status=overdue')->assertOk()->json();
        $this->assertSame(['Slipped last week'], array_column($overdue, 'description'));
    }

    /** Open issues sort above closed ones — an issue register shows what is left. */
    public function test_issue_register_puts_open_issues_first(): void
    {
        Sanctum::actingAs($this->user());

        $m = $this->meeting($this->vendor('IssueCo'), 'HSSE review');
        foreach ([['Closed one', 'Closed'], ['Open one', 'Open']] as [$title, $status]) {
            PurchaseMomIssue::create([
                'tenant_id' => self::TENANT, 'purchase_kickoff_meeting_id' => $m->id,
                'issue_ref' => 'I-'.strtoupper(Str::random(5)), 'title' => $title,
                'description' => $title, 'category' => 'HSSE', 'severity' => 'High',
                'owner_names' => 'Sam Owner', 'status' => $status,
            ]);
        }

        $rows = $this->getJson('/api/purchase/kickoff/registers/issues')->assertOk()->json();

        $this->assertCount(2, $rows);
        $this->assertSame('Open one', $rows[0]['title'], 'a closed issue sorted above an open one');
        $this->assertTrue($rows[0]['is_open']);
    }

    /** Decisions come back with their meeting context and are searchable. */
    public function test_decision_register_returns_context_and_filters_by_search(): void
    {
        Sanctum::actingAs($this->user());

        $m = $this->meeting($this->vendor('DecideCo'), 'Commercial review');
        foreach (['Approved the rate revision', 'Deferred the scope change'] as $text) {
            PurchaseMomDecision::create([
                'tenant_id' => self::TENANT, 'purchase_kickoff_meeting_id' => $m->id,
                'decision_ref' => 'D-'.strtoupper(Str::random(5)), 'decision' => $text,
                'decided_by_names' => 'Chair', 'status' => 'Active',
                'effective_date' => now()->subDay()->toDateString(),
            ]);
        }

        $all = $this->getJson('/api/purchase/kickoff/registers/decisions')->assertOk()->json();
        $this->assertCount(2, $all);
        $this->assertSame('DecideCo', $all[0]['vendor']);
        $this->assertSame('Commercial review', $all[0]['meeting_title']);

        $hits = $this->getJson('/api/purchase/kickoff/registers/decisions?search=rate')->assertOk()->json();
        $this->assertCount(1, $hits);
        $this->assertSame('Approved the rate revision', $hits[0]['decision']);
    }

    /** The vendor filter narrows to one company across every meeting it had. */
    public function test_registers_filter_by_vendor(): void
    {
        Sanctum::actingAs($this->user());

        $a = $this->vendor('KeepCo');
        $b = $this->vendor('DropCo');
        $this->action($this->meeting($a, 'One'), 'Keep this', 'Open');
        $this->action($this->meeting($b, 'Two'), 'Drop this', 'Open');

        $rows = $this->getJson('/api/purchase/kickoff/registers/actions?vendor=KeepCo')->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame('Keep this', $rows[0]['description']);
    }

    /** A register must never read another tenant's meetings. */
    public function test_registers_are_tenant_isolated(): void
    {
        $mine = $this->vendor('MineCo');
        $theirs = $this->vendor('TheirsCo', self::OTHER);
        $this->action($this->meeting($mine, 'Mine'), 'My action', 'Open');
        $this->action($this->meeting($theirs, 'Theirs', self::OTHER), 'Their action', 'Open', null);

        Sanctum::actingAs($this->user('admin', self::TENANT));
        $rows = $this->getJson('/api/purchase/kickoff/registers/actions')->assertOk()->json();

        $this->assertSame(['My action'], array_column($rows, 'description'));
    }

    /**
     * The filter options are built from the data, so the vendor list only offers
     * companies that actually have meetings.
     */
    public function test_register_options_expose_the_shared_filter_vocabulary(): void
    {
        Sanctum::actingAs($this->user());

        $this->meeting($this->vendor('OptionCo'), 'Planning');

        $body = $this->getJson('/api/purchase/kickoff/registers/options')->assertOk()->json();

        foreach (['decision_statuses', 'issue_statuses', 'issue_severities', 'issue_categories',
                  'action_statuses', 'priorities', 'convert_targets', 'vendors'] as $key) {
            $this->assertArrayHasKey($key, $body, "options is missing '{$key}'");
        }

        // Identical to the shared engine's, which is what lets one UI drive both.
        $this->assertSame(['Open', 'In_Progress', 'Resolved', 'Closed', 'Reopened', 'Cancelled'], $body['issue_statuses']);
        $this->assertContains('OptionCo', $body['vendors']);
    }

    /**
     * /kickoff/{kickoff} carries no numeric constraint, so a two-segment route
     * declared after it would be captured as a meeting id and 404. This pins the
     * ordering rather than trusting it.
     */
    public function test_word_routes_are_not_captured_as_a_meeting_id(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/purchase/kickoff/staff')->assertOk();
        $this->getJson('/api/purchase/kickoff/vendors')->assertOk();
        $this->getJson('/api/purchase/kickoff/registers/options')->assertOk();
    }

    /** The pickers list this tenant's own people and Purchase vendors only. */
    public function test_pickers_are_scoped_to_the_tenant_and_module(): void
    {
        $this->user('staff');
        $this->user('admin', self::OTHER);
        $this->vendor('VisibleCo');
        $this->vendor('HiddenCo', self::OTHER);

        Sanctum::actingAs($this->user('admin'));

        $staff = $this->getJson('/api/purchase/kickoff/staff')->assertOk()->json();
        foreach ($staff as $s) {
            $this->assertArrayHasKey('name', $s);
        }
        $this->assertGreaterThanOrEqual(2, count($staff));

        $vendors = array_column($this->getJson('/api/purchase/kickoff/vendors')->assertOk()->json(), 'company_name');
        $this->assertContains('VisibleCo', $vendors);
        $this->assertNotContains('HiddenCo', $vendors);
    }

    /**
     * The live snapshot a meeting is planned against. `has_history` is what the
     * UI uses to hide the carry-forward panel on a vendor's first meeting.
     */
    public function test_vendor_status_snapshot_reports_history_and_open_items(): void
    {
        Sanctum::actingAs($this->user());

        $vendor = $this->vendor('SnapCo');

        $fresh = $this->getJson('/api/purchase/kickoff/vendor-status?vendor_id='.$vendor->id)->assertOk()->json();
        $this->assertSame('SnapCo', $fresh['vendor']['name']);
        $this->assertFalse($fresh['has_history'], 'a vendor with no meetings should report no history');

        $m = $this->meeting($vendor, 'First');
        $this->action($m, 'Outstanding item', 'Open');

        $after = $this->getJson('/api/purchase/kickoff/vendor-status?vendor_id='.$vendor->id)->assertOk()->json();
        $this->assertTrue($after['has_history']);

        $prev = collect($after['sections'])->firstWhere('key', 'previous_mom');
        $this->assertNotNull($prev, 'the previous-MOM section is missing');
        $this->assertStringContainsString('1 open action', $prev['value']);
        $this->assertTrue($prev['flag'], 'an open action should flag the section for attention');
    }

    /** An unknown vendor is answered, not fataled — the panel is decoration. */
    public function test_vendor_status_handles_an_unknown_vendor(): void
    {
        Sanctum::actingAs($this->user());

        $body = $this->getJson('/api/purchase/kickoff/vendor-status?vendor_id=987654')->assertOk()->json();

        $this->assertNull($body['vendor']);
        $this->assertSame([], $body['sections']);
    }
}
