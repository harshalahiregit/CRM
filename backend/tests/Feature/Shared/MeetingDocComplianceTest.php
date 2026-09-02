<?php

namespace Tests\Feature\Shared;

use App\Models\Customer\Client;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\MeetingDistribution;
use App\Models\Shared\MeetingIssue;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Shared\KickoffMeetingService;
use App\Services\Shared\MeetingRegisterService;
use App\Support\Shared\KickoffStatus;
use App\Support\Shared\MeetingTypeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Meeting.docx requirements that had no coverage because they had no
 * implementation: the invitation send (§1), the organiser and customer fields
 * (§2), per-agenda-item discussion (§7), the cross-meeting registers (§8/§9/§10),
 * the remaining escalation targets (§10) and per-recipient distribution (§13).
 */
class MeetingDocComplianceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    private Vendor $vendor;

    // Built by hand rather than by factory, matching KickoffEnhancementsTest —
    // this project has no factories for Tenant/User/Vendor.
    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Coordinator',
            'email' => 'coord'.uniqid().'@test.com', 'password' => bcrypt('secret'),
            'role' => 'admin', 'status' => 'active',
        ]);

        $this->vendor = Vendor::create([
            'tenant_id' => self::TENANT,
            'company_name' => 'Acme Contracting',
            'email' => 'vendor'.uniqid().'@test.com',
        ]);
    }

    private function colleague(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya Nair',
            'email' => 'colleague'.uniqid().'@test.com', 'password' => bcrypt('secret'),
            'role' => 'staff', 'status' => 'active',
        ]);
    }

    private function client(): Client
    {
        return Client::create([
            'tenant_id' => self::TENANT,
            'company' => 'Northwind Industries',
        ]);
    }

    private function svc(): KickoffMeetingService
    {
        return app(KickoffMeetingService::class);
    }

    /** A meeting with a full §2 header, a linked customer and a linked participant. */
    private function meeting(array $overrides = []): KickoffMeeting
    {
        $colleague = $this->colleague();

        $payload = array_merge([
            'subject_type' => 'vendor',
            'subject_id' => $this->vendor->id,
            'title' => 'Weekly coordination',
            'meeting_type' => 'weekly_coordination',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'mode' => 'hybrid',
            'organizer' => 'O. Organiser',
            'chairperson' => 'C. Chair',
            'coordinator' => 'K. Coordinator',
            'department' => 'HSE',
            'work_package' => 'WP-01',
            'attendees' => [
                ['name' => $colleague->name, 'user_id' => $colleague->id, 'side' => 'internal', 'role' => 'Project Manager'],
                ['name' => 'Vendor Rep', 'email' => 'rep@vendor.test', 'side' => 'external', 'role' => 'Vendor representative'],
                ['name' => 'Unreachable', 'side' => 'internal', 'role' => 'Management'],
            ],
        ], $overrides);

        $m = $this->svc()->schedule($payload, $this->actor);

        // Meetings are now born as drafts; publish so these tests exercise the
        // live (Scheduled) lifecycle exactly as before the draft/publish split.
        // An intentionally undated meeting cannot be published — leave it a draft.
        if (! empty($payload['scheduled_at'])) {
            $m = $this->svc()->transition($m, KickoffStatus::SCHEDULED, [], $this->actor);
        }

        return $m;
    }

    /* ── §2 — organizer, customer and the rest of the header ──────────── */

    public function test_the_meeting_carries_an_organizer_and_a_linked_customer(): void
    {
        $client = $this->client();

        $m = $this->meeting(['client_id' => $client->id, 'client_name' => $client->company]);

        $this->assertSame('O. Organiser', $m->organizer);
        $this->assertSame($client->id, (int) $m->client_id);
        $this->assertSame('WP-01', $m->work_package);
        // The doc's Meeting ID is the tenant reference, not the row id.
        $this->assertMatchesRegularExpression('/^MTG-\d{4}-\d{4}$/', $m->meeting_no);
    }

    public function test_every_meeting_type_ships_a_standard_agenda_template(): void
    {
        $catalog = app(MeetingTypeCatalog::class);
        $types = $catalog->types($this->actor->tenant_id);
        $templates = $catalog->templates($this->actor->tenant_id);

        $missing = array_diff(array_keys($types), array_keys($templates));

        $this->assertSame([], $missing, 'every meeting type must offer a one-click agenda');
    }

    /* ── §5 — participants are linked identities, not typed names ─────── */

    public function test_a_participant_is_linked_to_a_sangoe_identity_and_keeps_its_email(): void
    {
        $m = $this->meeting();
        $attendees = $m->attendees;

        $linked = $attendees->firstWhere('role', 'Project Manager');
        $this->assertNotNull($linked->user_id, 'an internal participant must resolve to a login');
        $this->assertNotNull($linked->email, 'the identity supplies the address the roster never typed');
        $this->assertStringStartsWith('colleague', $linked->email);

        $external = $attendees->firstWhere('role', 'Vendor representative');
        $this->assertSame('rep@vendor.test', $external->email, 'a typed e-mail must survive the round trip');
    }

    /* ── §1 — the invitation ──────────────────────────────────────────── */

    public function test_scheduling_a_dated_meeting_sends_the_invitation(): void
    {
        $m = $this->meeting();

        $rows = MeetingDistribution::where('kickoff_meeting_id', $m->id)
            ->where('kind', MeetingDistribution::KIND_INVITE)->get();

        $this->assertCount(3, $rows, 'every participant is accounted for');
        $this->assertSame(2, $rows->where('status', MeetingDistribution::SENT)->count());
        // Someone with no address is recorded honestly rather than as delivered.
        $this->assertSame(1, $rows->where('status', MeetingDistribution::SKIPPED)->count());
        // §13's recipient groups are derived, not guessed.
        $this->assertEqualsCanonicalizing(
            ['internal', 'vendor', 'management'],
            $rows->pluck('party')->all()
        );
    }

    public function test_a_meeting_without_a_date_sends_nothing(): void
    {
        $m = $this->meeting(['scheduled_at' => null]);

        $this->assertSame(0, MeetingDistribution::where('kickoff_meeting_id', $m->id)->count(),
            'there is nothing to invite anyone to yet');
    }

    /* ── §7 — Agenda → Discussion → Decision ──────────────────────────── */

    public function test_discussion_and_decision_are_captured_against_the_agenda_item(): void
    {
        $m = $this->meeting(['agenda_items' => [[
            'item' => 'Workforce shortage',
            'discussion' => 'Vendor has 42 workers against a requirement of 50.',
            'decision' => 'Vendor to mobilise 8 additional workers.',
        ]]]);

        $item = $m->agendaItems->first();
        $this->assertSame('Vendor has 42 workers against a requirement of 50.', $item->discussion);
        $this->assertSame('Vendor to mobilise 8 additional workers.', $item->decision);
    }

    public function test_a_mom_action_cannot_progress_past_open_without_an_owner(): void
    {
        $m = $this->meeting(['mom_items' => [['description' => 'Mobilise workers', 'priority' => 'High']]]);
        $item = $m->momItems->first();
        $this->assertEmpty($item->responsible_attendee_id);
        $this->assertEmpty($item->responsible_names);

        // No owner → Rule 11 refuses progression.
        try {
            $this->svc()->progressAction($item, ['status' => 'In_Progress'], $this->actor);
            $this->fail('Expected a Rule 11 owner-gate failure.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('responsible owner', $e->getMessage());
        }

        // Assigning an owner in the same call lets it progress.
        $out = $this->svc()->progressAction($item, ['status' => 'In_Progress', 'responsible_names' => 'Asha'], $this->actor);
        $this->assertSame('In_Progress', $out->status);
        $this->assertSame('Asha', $out->responsible_names);
    }

    public function test_supporting_documents_and_previous_reference_persist_on_the_agenda_item(): void
    {
        $m = $this->meeting(['agenda_items' => [[
            'item' => 'Workforce shortage',
            'supporting_documents' => ['mobilisation-plan.pdf', 'roster.xlsx'],
            'previous_discussion_ref' => 'MOM-2026-014 item 3',
        ]]]);

        $item = $m->agendaItems->first();
        $this->assertSame(['mobilisation-plan.pdf', 'roster.xlsx'], $item->supporting_documents);
        $this->assertSame('MOM-2026-014 item 3', $item->previous_discussion_ref);
    }

    /* ── §8 / §9 / §10 — the cross-meeting registers ──────────────────── */

    public function test_the_registers_read_across_meetings_with_their_context(): void
    {
        $m = $this->meeting([
            'decisions' => [['decision' => 'Mobilise 8 workers', 'impact' => 'Schedule', 'status' => 'Active']],
            'issues' => [['title' => 'Workforce shortage', 'severity' => 'High', 'category' => 'Workforce', 'status' => 'Open']],
            'mom_items' => [['description' => 'Mobilise 8 workers', 'priority' => 'High']],
        ]);

        $reg = app(MeetingRegisterService::class);

        $decisions = $reg->decisions($this->actor->tenant_id);
        $this->assertCount(1, $decisions);
        $this->assertSame($m->meeting_no, $decisions[0]['meeting_no'], 'a register row points back at its meeting');
        $this->assertSame($this->vendor->company_name, $decisions[0]['vendor']);

        $this->assertCount(1, $reg->issues($this->actor->tenant_id));
        $this->assertCount(1, $reg->actions($this->actor->tenant_id));

        // Filters narrow rather than crash on an unknown value.
        $this->assertCount(0, $reg->decisions($this->actor->tenant_id, ['vendor' => 'Nobody Ltd']));
        $this->assertCount(1, $reg->decisions($this->actor->tenant_id, ['search' => 'Mobilise']));
    }

    /* ── §10 — every escalation target creates a real record ──────────── */

    public function test_an_issue_can_be_escalated_into_an_ncr_a_capa_and_an_approval(): void
    {
        foreach ([['convertIssueToNcr', 'NCR'], ['convertIssueToCapa', 'CAPA'], ['convertIssueToApproval', 'Approval']] as [$method, $kind]) {
            $m = $this->meeting(['issues' => [[
                'title' => "Escalate me to {$kind}", 'severity' => 'High', 'status' => 'Open',
            ]]]);
            $issue = $m->issues->first();

            $out = $this->svc()->$method($issue, [], $this->actor);

            $this->assertSame($kind, $out->converted_to);
            $this->assertNotNull($out->converted_ref, 'the created record must be referenced back');
            $this->assertNotNull($out->converted_id);
        }
    }

    public function test_an_issue_cannot_be_escalated_twice(): void
    {
        $m = $this->meeting(['issues' => [['title' => 'One escalation only', 'severity' => 'Medium', 'status' => 'Open']]]);
        $issue = $m->issues->first();

        $this->svc()->convertIssueToNcr($issue, [], $this->actor);

        $this->expectExceptionMessage('already converted');
        $this->svc()->convertIssueToNcr($issue->fresh(), [], $this->actor);
    }

    /* ── §6 — saving attendance with anyone left unmarked ─────────────── */

    /**
     * The form posts EVERY roster row on save, with attendance_status = null for
     * anyone not yet marked. `required_without` read that null as "absent" and
     * rejected the whole request, so attendance could only be saved by marking
     * every single person — found by driving the real modal in a browser.
     */
    public function test_attendance_saves_when_some_attendees_are_left_unmarked(): void
    {
        $m = $this->meeting();
        $this->actingAs($this->actor);

        $rows = $m->attendees->map(fn ($a, $i) => [
            'id' => $a->id,
            'attendance_status' => $i === 0 ? 'Late' : null,
            'remark' => $i === 0 ? 'Joined 10 minutes late' : null,
        ])->all();

        $this->patchJson("/api/kickoff/meetings/{$m->id}/attendance", ['attendance' => $rows])
            ->assertOk();

        $fresh = $m->fresh('attendees')->attendees;
        $this->assertSame('Late', $fresh[0]->attendance_status);
        $this->assertSame('Joined 10 minutes late', $fresh[0]->remark);
        $this->assertTrue((bool) $fresh[0]->attended, 'Late counts as having turned up');
        // Unmarked stays unmarked — it must never be written as Absent.
        $this->assertNull($fresh[1]->attendance_status);
        $this->assertFalse((bool) $fresh[1]->attended);
    }

    /** A row carrying neither field is still refused — it would do nothing. */
    public function test_an_attendance_row_with_no_instruction_is_refused(): void
    {
        $m = $this->meeting();
        $this->actingAs($this->actor);

        $this->patchJson("/api/kickoff/meetings/{$m->id}/attendance", [
            'attendance' => [['id' => $m->attendees->first()->id]],
        ])->assertStatus(422);
    }

    /* ── §13 — distribution reaches every group, tracked per person ───── */

    public function test_distribution_records_every_recipient_group(): void
    {
        $client = $this->client();

        $m = $this->meeting([
            'scheduled_at' => now()->subHour()->toDateTimeString(),
            'client_id' => $client->id,
        ]);

        $this->svc()->transition($m, KickoffStatus::COMPLETED, [], $this->actor);
        $m->fresh()->update(['mom_path' => 'fake/mom.pdf']);
        $this->svc()->submitMomForApproval($m->fresh(), $this->actor);
        $this->svc()->decideMom($m->fresh(), 'approve', null, $this->actor);
        $this->svc()->decideMom($m->fresh(), 'approve', null, $this->actor);
        $this->svc()->distributeMom($m->fresh(), $this->actor);

        $tracker = $this->svc()->distributionTracker($m->fresh());

        $parties = collect($tracker['mom'])->pluck('party')->unique();
        $this->assertTrue($parties->contains('vendor'), 'the vendor must receive the minutes');
        $this->assertTrue($parties->contains('internal'), 'so must our own side');
        $this->assertTrue($parties->contains('management'), 'and Management is its own group');

        // Sent / Viewed / Acknowledged, per person rather than per meeting.
        $this->assertGreaterThan(0, $tracker['totals']['sent']);
        $this->assertSame(0, $tracker['totals']['viewed'], 'nobody has opened it yet');
        $this->assertSame(1, $tracker['totals']['no_address'], 'a recipient with no address is never "sent"');
    }

    /* ── The screens behind the doc, over HTTP ───────────────────────── */

    /**
     * §9's "searchable Decision Register" and §10's issue register, as the two
     * new screens actually call them. These were the two things that could not
     * be found anywhere in the app, so the endpoints behind them are pinned.
     */
    public function test_the_register_screens_are_reachable_over_http(): void
    {
        $this->meeting([
            'decisions' => [['decision' => 'Mobilise 8 workers', 'status' => 'Active']],
            'issues' => [['title' => 'Workforce shortage', 'severity' => 'High', 'status' => 'Open']],
            'mom_items' => [['description' => 'Mobilise 8 workers']],
        ]);

        $this->actingAs($this->actor);

        $this->getJson('/api/kickoff/registers/decisions')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.decision', 'Mobilise 8 workers');

        $this->getJson('/api/kickoff/registers/issues')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Workforce shortage');

        $this->getJson('/api/kickoff/registers/actions')
            ->assertOk()->assertJsonCount(1);

        $this->getJson('/api/kickoff/registers/options')
            ->assertOk()->assertJsonStructure(['decision_statuses', 'issue_severities', 'action_statuses']);
    }

    /** §2 and §5's pickers, read through the owning modules' contracts. */
    public function test_the_customer_and_staff_pickers_are_reachable(): void
    {
        $this->client();
        $this->colleague();
        $this->actingAs($this->actor);

        $this->getJson('/api/kickoff/customers')->assertOk();

        $staff = $this->getJson('/api/kickoff/staff')->assertOk()->json();
        $this->assertNotEmpty($staff);
        // A picker identifies a colleague; it does not expose the staff table.
        $this->assertSame(['id', 'name', 'email', 'designation'], array_keys($staff[0]));
    }

    /** §1's Send Invitation and §13's tracker, from the meeting detail screen. */
    public function test_invitation_and_distribution_endpoints_work(): void
    {
        $m = $this->meeting();
        $this->actingAs($this->actor);

        $this->postJson("/api/kickoff/meetings/{$m->id}/invite")
            ->assertOk()
            ->assertJsonStructure(['sent', 'skipped', 'failed', 'in_app', 'recipients']);

        $this->getJson("/api/kickoff/meetings/{$m->id}/distribution")
            ->assertOk()
            ->assertJsonStructure(['invite', 'mom', 'totals' => ['sent', 'viewed', 'acknowledged', 'no_address']]);
    }

    /** A meeting with no date cannot invite anyone to it. */
    public function test_inviting_an_undated_meeting_is_refused(): void
    {
        $m = $this->meeting(['scheduled_at' => null]);
        $this->actingAs($this->actor);

        $this->postJson("/api/kickoff/meetings/{$m->id}/invite")
            ->assertStatus(422);
    }

    /** §10's three new escalation routes, from the issue row on the detail page. */
    public function test_the_escalation_routes_are_wired(): void
    {
        foreach (['ncr' => 'NCR', 'capa' => 'CAPA', 'approval' => 'Approval'] as $path => $kind) {
            $m = $this->meeting(['issues' => [['title' => "Escalate to {$kind}", 'severity' => 'High', 'status' => 'Open']]]);
            $issue = $m->issues->first();

            $this->actingAs($this->actor)
                ->postJson("/api/kickoff/meetings/{$m->id}/issues/{$issue->id}/convert-{$path}")
                ->assertOk()
                ->assertJsonPath('converted_to', $kind);
        }
    }

    /** A register never leaks another tenant's meetings. */
    public function test_registers_are_tenant_scoped(): void
    {
        $this->meeting(['decisions' => [['decision' => 'Tenant one decision', 'status' => 'Active']]]);

        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $outsider = User::create([
            'tenant_id' => 2, 'name' => 'Outsider',
            'email' => 'outsider'.uniqid().'@test.com', 'password' => bcrypt('secret'),
            'role' => 'admin', 'status' => 'active',
        ]);

        $this->actingAs($outsider)
            ->getJson('/api/kickoff/registers/decisions')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_opening_a_recipients_link_marks_only_that_recipient_viewed(): void
    {
        $m = $this->meeting(['scheduled_at' => now()->subHour()->toDateTimeString()]);

        $this->svc()->transition($m, KickoffStatus::COMPLETED, [], $this->actor);
        $m->fresh()->update(['mom_path' => 'fake/mom.pdf']);
        $this->svc()->submitMomForApproval($m->fresh(), $this->actor);
        $this->svc()->decideMom($m->fresh(), 'approve', null, $this->actor);
        $this->svc()->decideMom($m->fresh(), 'approve', null, $this->actor);
        $this->svc()->distributeMom($m->fresh(), $this->actor);

        $row = MeetingDistribution::where('kickoff_meeting_id', $m->id)
            ->where('kind', MeetingDistribution::KIND_MOM)
            ->whereNotNull('token')->whereNotNull('email')->first();

        $this->svc()->markMomViewed($m->fresh(), $row->token);

        $this->assertNotNull($row->fresh()->viewed_at);
        $this->assertSame(1, $this->svc()->distributionTracker($m->fresh())['totals']['viewed'],
            'one person reading the minutes must not mark everyone as having read them');
    }
}
