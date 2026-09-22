<?php

namespace Tests\Feature\Hr;

use App\Models\AuditLog;
use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseMember;
use App\Models\Hr\HrPoshCaseRead;
use App\Models\Hr\HrPoshCommittee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\Posh\PoshCaseReadAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recording that somebody read a case.
 *
 * On a harassment file, who opened it is itself part of the record. Nothing
 * else in this product logs a read — AuditLogService is only ever called on
 * writes — so this is new capability rather than a wrapper over something that
 * already existed.
 *
 * Two properties carry the weight.
 *
 * ONLY SUCCESSFUL READS LAND HERE. A refused attempt read nothing, and a row
 * claiming otherwise would make the table mean two things at once. Refusals
 * are security events and belong on the ordinary audit trail.
 *
 * THE TABLE IS SEPARATE FROM audit_logs, deliberately. Every audit browser in
 * the product queries audit_logs; a POSH read appearing there would surface
 * who opened a harassment file on a screen built for approvals and onboarding.
 */
class PoshCaseReadAuditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'posh-read', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(string $role = 'staff', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U'.substr(uniqid(), -4),
            'email' => uniqid().'@read.test', 'password' => Hash::make('Password123!'),
            'role' => $role, 'status' => 'active', 'internal_role' => $internal,
        ]);
    }

    private function poshCase(): HrPoshCase
    {
        $committee = HrPoshCommittee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'ICC '.substr(uniqid(), -5),
            'quorum_mode' => HrPoshCommittee::QUORUM_ALL, 'is_active' => true, 'sort_order' => 0,
        ]);

        return HrPoshCase::create([
            'tenant_id' => $this->tenant->id, 'reference' => 'POSH-'.substr(uniqid(), -6),
            'committee_id' => $committee->id,
            'complainant_type' => HrPoshCase::COMPLAINANT_EMPLOYEE,
            'narrative' => 'What happened.', 'status' => HrPoshCase::STATUS_RECEIVED,
        ]);
    }

    /** A case with one member who can read it. */
    private function caseWithMember(): array
    {
        $case = $this->poshCase();
        $member = $this->user();
        HrPoshCaseMember::grant($case, $member->id, 'internal_member');

        return [$case, $member];
    }

    /* ── one row per authorised read ──────────────────────────────────── */

    public function test_reading_a_case_records_exactly_one_read(): void
    {
        [$case, $member] = $this->caseWithMember();

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();

        $this->assertSame(1, HrPoshCaseRead::where('case_id', $case->id)->count());
        $this->assertSame(PoshCaseReadAuditor::SURFACE_SHOW,
            HrPoshCaseRead::where('case_id', $case->id)->value('surface'));
    }

    public function test_reading_the_roster_records_its_own_surface(): void
    {
        [$case, $member] = $this->caseWithMember();

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}/members")->assertOk();

        $row = HrPoshCaseRead::where('case_id', $case->id)->firstOrFail();
        $this->assertSame(PoshCaseReadAuditor::SURFACE_MEMBERS, $row->surface);
    }

    public function test_each_read_is_recorded_separately(): void
    {
        [$case, $member] = $this->caseWithMember();

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();
        $this->getJson("/api/hr/posh-cases/{$case->id}/members")->assertOk();

        // Three reads, three rows. Nothing is collapsed or de-duplicated —
        // "opened it twice" is a different fact from "opened it once".
        $this->assertSame(3, HrPoshCaseRead::where('case_id', $case->id)->count());
    }

    public function test_the_read_carries_everything_needed_to_answer_who_and_when(): void
    {
        [$case, $member] = $this->caseWithMember();

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();

        $row = HrPoshCaseRead::where('case_id', $case->id)->firstOrFail();

        $this->assertSame($member->id, (int) $row->actor_id);
        $this->assertSame($member->name, $row->actor_label);
        $this->assertSame($this->tenant->id, (int) $row->tenant_id);
        $this->assertSame($case->id, (int) $row->case_id);
        $this->assertSame(PoshCaseReadAuditor::SURFACE_SHOW, $row->surface);
        $this->assertNotNull($row->read_at);
    }

    public function test_the_actor_label_is_always_written(): void
    {
        [$case, $member] = $this->caseWithMember();

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();

        // The trail is never anonymous. A later phase's token complainant has
        // no user id and will be identified by label alone.
        $this->assertNotEmpty(HrPoshCaseRead::where('case_id', $case->id)->value('actor_label'));
    }

    /* ── refusals record nothing here ─────────────────────────────────── */

    public function test_a_refused_read_records_no_read(): void
    {
        $case = $this->poshCase();

        foreach ([
            $this->user(),
            $this->user(role: 'admin'),
            $this->user(internal: 'hr_executive'),
        ] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson("/api/hr/posh-cases/{$case->id}")->assertStatus(404);
        }

        // Nobody read anything, so there is nothing to record. A row here
        // would claim content was served when it was not.
        $this->assertSame(0, HrPoshCaseRead::where('case_id', $case->id)->count());
    }

    public function test_a_removed_member_stops_generating_reads(): void
    {
        [$case, $member] = $this->caseWithMember();

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();

        HrPoshCaseMember::revoke($case, $member->id, null, 'Recused.');

        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertStatus(404);

        // The read from while they were a member stays; no new one is added.
        $this->assertSame(1, HrPoshCaseRead::where('case_id', $case->id)->count());
    }

    public function test_the_read_is_recorded_after_authorization_not_before(): void
    {
        $case = $this->poshCase();
        $outsider = $this->user();

        Sanctum::actingAs($outsider);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertStatus(404);

        // Order matters: a row written before the check would claim a read the
        // next line refused.
        $this->assertSame(0, HrPoshCaseRead::where('actor_id', $outsider->id)->count());
    }

    /* ── append only ──────────────────────────────────────────────────── */

    public function test_the_model_keeps_no_updated_timestamp(): void
    {
        [$case, $member] = $this->caseWithMember();
        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();

        // Nothing updates a read, so the column would never differ from
        // read_at — and its absence makes that impossible to change quietly.
        $this->assertFalse((new HrPoshCaseRead)->usesTimestamps());
        $this->assertNotContains('updated_at', \Schema::getColumnListing('hr_posh_case_reads'));
    }

    public function test_the_auditor_offers_no_way_to_change_or_remove_a_read(): void
    {
        // The value of the table is that it cannot be tidied up afterwards, so
        // there is deliberately no method that could.
        $methods = get_class_methods(PoshCaseReadAuditor::class);

        $this->assertSame(['record'], array_values(array_diff($methods, ['__construct'])));
    }

    /* ── kept out of every generic surface ────────────────────────────── */

    public function test_reads_do_not_appear_in_the_shared_audit_log(): void
    {
        [$case, $member] = $this->caseWithMember();

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();

        // audit_logs is what every audit browser in the product queries. A
        // POSH read must not be reachable through any of them.
        $this->assertSame(0, AuditLog::where('auditable_type', HrPoshCase::class)
            ->where('action', 'like', '%Read%')->count());
        $this->assertSame(0, AuditLog::where('auditable_type', HrPoshCaseRead::class)->count());
    }

    public function test_no_route_returns_the_read_log(): void
    {
        [$case, $member] = $this->caseWithMember();

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();

        // Not even to a member of the case. Nothing reads this table today.
        foreach ([
            "/api/hr/posh-cases/{$case->id}/reads",
            "/api/hr/posh-cases/{$case->id}/audit",
            '/api/hr/posh-case-reads',
        ] as $path) {
            $this->getJson($path)->assertStatus(404);
        }
    }

    public function test_the_case_payload_never_carries_its_read_log(): void
    {
        [$case, $member] = $this->caseWithMember();

        Sanctum::actingAs($member);
        $body = $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk()->json();

        $this->assertArrayNotHasKey('reads', $body['data']);
        $this->assertStringNotContainsString('read_at', json_encode($body));
    }

    /* ── tenancy ──────────────────────────────────────────────────────── */

    public function test_a_read_is_stamped_with_the_cases_tenant(): void
    {
        [$case, $member] = $this->caseWithMember();

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();

        $this->assertSame(
            $this->tenant->id,
            (int) HrPoshCaseRead::where('case_id', $case->id)->value('tenant_id')
        );
    }

    /* ── a failure to record must not cost the read ───────────────────── */

    public function test_a_broken_read_log_does_not_break_the_request(): void
    {
        [$case, $member] = $this->caseWithMember();

        // A real failure, not a simulated one: with the table gone every
        // insert throws. The auditor swallows and logs, so a member who is
        // legitimately entitled to the case still gets it.
        \Schema::drop('hr_posh_case_reads');

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();
    }
}
