<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The four tabs under Purchase > Meetings, end to end.
 *
 * All Meetings, Decision Register, Issue Register, Open Action Items.
 *
 * A register that returns 200 with an empty array looks identical, from the
 * screen, to a register that is broken — both show "no rows". So this does not
 * check that the endpoints answer; it creates a meeting carrying one decision,
 * one issue and one action, and then asserts each register actually finds its
 * own row. That is the difference between the plumbing being connected and the
 * water arriving.
 */
class PurchaseMeetingsTabsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate Industrial',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A meeting carrying one of each thing the registers report on. */
    private function meetingWithContent(): int
    {
        $res = $this->postJson('/api/purchase/kickoff', [
            'purchase_vendor_id' => $this->vendor->id,
            'title' => 'Quarterly review',
            'meeting_type' => 'progress_review',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'mode' => 'online',
            'decisions' => [[
                'decision' => 'Approve the revised rate card',
                'status' => 'Active',
            ]],
            'issues' => [[
                'title' => 'Crane certification expired',
                'severity' => 'High',
                'status' => 'Open',
            ]],
            'mom_items' => [[
                'description' => 'Renew the lifting certificate',
                'priority' => 'High',
                // Rule 11: an action with no owner is a note, not an action.
                // The module refuses it, which is right — an unowned action is
                // exactly the kind that is still open a year later.
                'responsible_names' => 'Ravi Kumar',
            ]],
        ]);

        // Name the fields when the fixture itself is refused — otherwise all
        // four tab tests fail with "422" and nothing says which key was wrong.
        if ($res->getStatusCode() >= 400) {
            $this->fail('could not create the meeting fixture: '.$res->getStatusCode()
                .' — '.json_encode($res->json('errors') ?? $res->json('message')));
        }

        return (int) ($res->json('id') ?? $res->json('meeting.id'));
    }

    /* ── Tab 1: All Meetings ────────────────────────────────────────── */

    public function test_all_meetings_lists_the_meeting(): void
    {
        Sanctum::actingAs($this->admin());
        $this->meetingWithContent();

        $rows = $this->getJson('/api/purchase/kickoff')->assertOk()->json();
        $rows = $rows['data'] ?? $rows;

        $this->assertNotEmpty($rows, 'the All Meetings tab shows nothing');
        $this->assertSame('Quarterly review', $rows[0]['title'] ?? null);
    }

    public function test_the_meetings_dashboard_and_stats_answer(): void
    {
        Sanctum::actingAs($this->admin());
        $this->meetingWithContent();

        // The cards above the list.
        $this->getJson('/api/purchase/kickoff/stats')->assertOk();
        $this->getJson('/api/purchase/kickoff/dashboard')->assertOk();
    }

    /* ── Tab 2: Decision Register ───────────────────────────────────── */

    public function test_the_decision_register_finds_the_decision(): void
    {
        Sanctum::actingAs($this->admin());
        $this->meetingWithContent();

        $body = $this->getJson('/api/purchase/kickoff/registers/decisions')->assertOk()->getContent();

        $this->assertStringContainsString('Approve the revised rate card', $body,
            'the Decision Register does not show a decision recorded on a meeting');
    }

    /* ── Tab 3: Issue Register ──────────────────────────────────────── */

    public function test_the_issue_register_finds_the_issue(): void
    {
        Sanctum::actingAs($this->admin());
        $this->meetingWithContent();

        $body = $this->getJson('/api/purchase/kickoff/registers/issues')->assertOk()->getContent();

        $this->assertStringContainsString('Crane certification expired', $body,
            'the Issue Register does not show an issue raised on a meeting');
    }

    /* ── Tab 4: Open Action Items ───────────────────────────────────── */

    public function test_the_action_register_finds_the_open_action(): void
    {
        Sanctum::actingAs($this->admin());
        $this->meetingWithContent();

        $body = $this->getJson('/api/purchase/kickoff/registers/actions')->assertOk()->getContent();

        $this->assertStringContainsString('Renew the lifting certificate', $body,
            'Open Action Items does not show an action raised on a meeting');
    }

    /** The filter bar above all three registers. */
    public function test_the_register_filter_options_load(): void
    {
        Sanctum::actingAs($this->admin());
        $this->meetingWithContent();

        // An empty options payload leaves every filter dropdown blank, which
        // reads as a broken screen even when the rows below are correct.
        $this->getJson('/api/purchase/kickoff/registers/options')->assertOk();
    }

    /* ── the pickers the meeting form needs ─────────────────────────── */

    public function test_the_meeting_form_can_load_its_pickers(): void
    {
        Sanctum::actingAs($this->admin());

        // Without these the New Meeting form opens with empty dropdowns and
        // cannot be submitted — the form looks broken though nothing threw.
        // The type catalogue lives at /purchase/meeting-types, not under
        // /kickoff — this is the path the meeting form actually calls.
        $this->getJson('/api/purchase/meeting-types')->assertOk();
        $this->getJson('/api/purchase/kickoff/vendors')->assertOk();
        $this->getJson('/api/purchase/kickoff/staff')->assertOk();
        $this->getJson('/api/purchase/kickoff/participants')->assertOk();
    }

    public function test_the_meeting_form_offers_real_customers(): void
    {
        \App\Models\Customer\Client::create([
            'tenant_id' => self::TENANT, 'company' => 'Northwind Traders',
            'email' => 'ap@northwind.local',
        ]);

        Sanctum::actingAs($this->admin());

        // This endpoint used to `return response()->json([])` — a stub that was
        // never finished, so the picker read "No customers found" on every
        // Purchase meeting however many customers the tenant had.
        $rows = $this->getJson('/api/purchase/kickoff/customers')->assertOk()->json();
        $rows = $rows['data'] ?? $rows;

        $this->assertNotEmpty($rows, 'the meeting form still shows no customers');
        $this->assertSame('Northwind Traders',
            $rows[0]['name'] ?? $rows[0]['company'] ?? null);
    }
}
