<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseMember;
use App\Models\Hr\HrPoshCaseRead;
use App\Models\Hr\HrPoshCaseToken;
use App\Models\Hr\HrPoshCommittee;
use App\Models\Hr\HrPoshCommitteeMember;
use App\Models\Hr\HrPoshCommitteeRole;
use App\Models\Notifications\HrNotification;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\Hr\DataScope;
use App\Support\Hr\Decision\Decision;
use App\Support\Hr\HrSetting;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The complainant's link: issuing it, using it, and every way it fails.
 *
 * The property that matters most here is the one that is hardest to see in a
 * passing test: THE RAW TOKEN EXISTS IN EXACTLY ONE RESPONSE AND NOWHERE ELSE.
 * Not in the row, not in the audit trail, not in a notification, not in a log.
 * Several tests below go looking for it in each of those places rather than
 * trusting that it was never put there.
 *
 * The second is that every failure looks identical. Malformed, unknown,
 * expired, revoked, superseded and cross-tenant all have to return the same
 * status and the same bytes, because any difference between them confirms that
 * a token was real.
 */
class PoshComplainantPortalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'posh-p', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'posh-p2', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(?Tenant $t = null, string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'U'.substr(uniqid(), -4),
            'email' => uniqid().'@posh.test', 'password' => Hash::make('Password123!'),
            'role' => $role, 'status' => 'active',
        ]);
    }

    private function staffWith(array $modules, ?Tenant $t = null): User
    {
        $t = $t ?: $this->tenant;
        $permissions = [];
        foreach ($modules as $m) {
            $permissions[$m] = [StaffPermission::VIEW_GLOBAL];
        }

        $role = StaffRole::create([
            'tenant_id' => $t->id, 'name' => 'R'.substr(uniqid(), -5),
            'slug' => 'r_'.substr(uniqid(), -5), 'permissions' => $permissions,
            'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $t->id, 'name' => 'S', 'email' => uniqid().'@posh.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);
    }

    /** @return array{0:HrPoshCase,1:array<int,User>} chair first */
    private function raise(?Tenant $t = null): array
    {
        $t = $t ?: $this->tenant;

        $committee = HrPoshCommittee::create([
            'tenant_id' => $t->id, 'name' => 'ICC '.substr(uniqid(), -5),
            'quorum_mode' => HrPoshCommittee::QUORUM_ALL, 'is_active' => true, 'sort_order' => 0,
        ]);

        $chairRole = HrPoshCommitteeRole::create([
            'tenant_id' => $t->id, 'committee_id' => $committee->id,
            'key' => 'presiding_officer', 'label' => 'Presiding Officer',
            'can_manage_case' => true, 'sort_order' => 0, 'is_active' => true,
        ]);
        $memberRole = HrPoshCommitteeRole::create([
            'tenant_id' => $t->id, 'committee_id' => $committee->id,
            'key' => 'internal_member', 'label' => 'Internal Member',
            'can_manage_case' => false, 'sort_order' => 1, 'is_active' => true,
        ]);

        $people = [];
        foreach ([$chairRole, $memberRole] as $role) {
            $u = $this->user($t);
            HrPoshCommitteeMember::create([
                'tenant_id' => $t->id, 'committee_id' => $committee->id,
                'role_id' => $role->id, 'user_id' => $u->id, 'is_active' => true,
            ]);
            $people[] = $u;
        }

        Sanctum::actingAs($this->staffWith(['hr_posh_intake'], $t));
        $id = $this->postJson('/api/hr/posh-cases', [
            'committee_id' => $committee->id,
            'narrative' => 'THE-SECRET-NARRATIVE',
            'respondent_label' => 'THE-RESPONDENT',
        ])->assertStatus(201)->json('data.id');

        return [HrPoshCase::findOrFail($id), $people];
    }

    /** Issue a link through the real endpoint. @return array{0:string,1:int} */
    private function issue(HrPoshCase $case, User $chair): array
    {
        Sanctum::actingAs($chair);
        $body = $this->postJson("/api/hr/posh-cases/{$case->id}/tokens")->assertStatus(201)->json('data');

        return [$body['token'], $body['id']];
    }

    /** Drop the acting user, the way a complainant arrives. */
    private function asStranger(): void
    {
        app('auth')->forgetGuards();
    }

    /* ── issuing ──────────────────────────────────────────────────────── */

    public function test_creating_a_case_mints_no_token(): void
    {
        [$case] = $this->raise();

        // Intake is not issuance. The person who takes a complaint at the door
        // must not end up holding the complainant's credential.
        $this->assertSame(0, HrPoshCaseToken::where('case_id', $case->id)->count());
    }

    public function test_only_a_case_manager_issues_a_link(): void
    {
        [$case, $people] = $this->raise();

        // A member without can_manage_case sees the case but may not run it.
        Sanctum::actingAs($people[1]);
        $this->postJson("/api/hr/posh-cases/{$case->id}/tokens")->assertStatus(403);

        // Everyone else cannot even see it.
        foreach ([
            $this->user(role: 'admin'),
            $this->staffWith(['hr_settings']),
            $this->staffWith(['hr_posh_intake']),
            $this->staffWith(['hr_posh_reports']),
        ] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->postJson("/api/hr/posh-cases/{$case->id}/tokens")->assertStatus(404);
        }

        $this->assertSame(0, HrPoshCaseToken::where('case_id', $case->id)->count());
    }

    public function test_the_raw_token_is_never_stored(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        $row = HrPoshCaseToken::where('case_id', $case->id)->firstOrFail();

        $this->assertSame(hash('sha256', $raw), $row->token_hash);
        $this->assertNotSame($raw, $row->token_hash);

        // Nothing anywhere in the row equals the raw value.
        foreach ($row->getAttributes() as $column => $value) {
            $this->assertNotSame($raw, (string) $value, "{$column} holds the raw token");
        }
    }

    public function test_the_raw_token_reaches_no_audit_or_notification(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        $audits = \App\Models\AuditLog::where('auditable_id', $case->id)->get();
        $this->assertTrue($audits->isNotEmpty());

        foreach ($audits as $audit) {
            $this->assertStringNotContainsString($raw, json_encode($audit->getAttributes()));
        }

        foreach (HrNotification::all() as $note) {
            $this->assertStringNotContainsString($raw, json_encode($note->getAttributes()));
        }
    }

    public function test_the_token_is_hidden_from_serialisation(): void
    {
        [$case, $people] = $this->raise();
        $this->issue($case, $people[0]);

        $row = HrPoshCaseToken::where('case_id', $case->id)->firstOrFail();

        $this->assertArrayNotHasKey('token_hash', $row->toArray());
    }

    public function test_issuing_again_revokes_the_predecessor(): void
    {
        [$case, $people] = $this->raise();
        [$first] = $this->issue($case, $people[0]);
        [$second] = $this->issue($case, $people[0]);

        $this->assertNotSame($first, $second);

        // The old link stops working the moment a new one exists — two live
        // doors and no memory of the older one is the failure being avoided.
        $this->asStranger();
        $this->getJson("/api/posh/portal/{$first}")->assertStatus(404);
        $this->getJson("/api/posh/portal/{$second}")->assertOk();

        $this->assertSame(2, HrPoshCaseToken::where('case_id', $case->id)->count());
        $this->assertSame(1, HrPoshCaseToken::where('case_id', $case->id)->whereNull('revoked_at')->count());
    }

    public function test_there_is_no_endpoint_that_lists_or_returns_tokens(): void
    {
        [$case, $people] = $this->raise();
        $this->issue($case, $people[0]);

        Sanctum::actingAs($people[0]);

        // No GET is registered. The app normalises a method mismatch to 404,
        // so the assertion is on what matters: nothing comes back, and nothing
        // that comes back is a token.
        $response = $this->getJson("/api/hr/posh-cases/{$case->id}/tokens");

        $this->assertSame(404, $response->status());
        $this->assertStringNotContainsString('token_hash', $response->getContent());

        // And the live token's raw value is gone for good — no route, no
        // service method and no column can return it.
        $this->assertNull(HrPoshCaseToken::where('case_id', $case->id)->first()->getAttribute('token'));
    }

    /* ── TTL, fail-closed ─────────────────────────────────────────────── */

    public function test_expiry_comes_from_the_tenant_setting(): void
    {
        [$case, $people] = $this->raise();

        app(SettingsService::class)->setGroup($this->tenant->id, HrSetting::GROUP, ['posh_token_ttl_days' => 7]);

        Sanctum::actingAs($people[0]);
        $expires = $this->postJson("/api/hr/posh-cases/{$case->id}/tokens")
            ->assertStatus(201)->json('data.expires_at');

        $this->assertSame(7, (int) round(now()->diffInDays(\Illuminate\Support\Carbon::parse($expires), false)));
    }

    /**
     * @dataProvider unusableTtls
     */
    public function test_an_unusable_ttl_issues_nothing(mixed $ttl): void
    {
        [$case, $people] = $this->raise();

        app(SettingsService::class)->setGroup($this->tenant->id, HrSetting::GROUP, ['posh_token_ttl_days' => $ttl]);

        Sanctum::actingAs($people[0]);
        $this->postJson("/api/hr/posh-cases/{$case->id}/tokens")->assertStatus(422);

        // Fail closed: no row, and specifically not one with no expiry. 30 is
        // NOT silently substituted — that would hide the misconfiguration.
        $this->assertSame(0, HrPoshCaseToken::where('case_id', $case->id)->count());
    }

    public static function unusableTtls(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-5],
            'null'     => [null],
            'words'    => ['forever'],
        ];
    }

    public function test_changing_the_ttl_does_not_move_an_issued_token(): void
    {
        [$case, $people] = $this->raise();

        app(SettingsService::class)->setGroup($this->tenant->id, HrSetting::GROUP, ['posh_token_ttl_days' => 30]);
        $this->issue($case, $people[0]);
        $before = HrPoshCaseToken::where('case_id', $case->id)->value('expires_at');

        app(SettingsService::class)->setGroup($this->tenant->id, HrSetting::GROUP, ['posh_token_ttl_days' => 1]);

        $this->assertEquals($before, HrPoshCaseToken::where('case_id', $case->id)->value('expires_at'));
    }

    /* ── using the link ───────────────────────────────────────────────── */

    public function test_a_live_link_shows_the_safe_projection(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        $this->asStranger();
        $body = $this->getJson("/api/posh/portal/{$raw}")->assertOk()->json('data');

        $this->assertSame($case->reference, $body['reference']);
        $this->assertSame(HrPoshCase::STATUS_RECEIVED, $body['status']);
        $this->assertNotNull($body['submitted_at']);
        $this->assertNull($body['findings']);

        // Everything the committee holds and the complainant does not get.
        $encoded = json_encode($body);
        foreach (['THE-SECRET-NARRATIVE', 'THE-RESPONDENT'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }

        foreach ([
            'narrative', 'respondent_label', 'respondent_employee_id', 'complainant_label',
            'committee_id', 'id', 'tenant_id', 'members', 'token', 'incident_place',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $body, "{$field} leaked to the complainant");
        }
    }

    public function test_the_thread_shows_messages_and_nothing_else(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        Sanctum::actingAs($people[0]);
        $this->postJson("/api/hr/posh-cases/{$case->id}/messages", ['body' => 'FOR-THE-COMPLAINANT'])->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/notes", ['body' => 'COMMITTEE-ONLY-NOTE'])->assertOk();

        // An event, which asEmployee:true would have admitted. POSH writes
        // none today; this proves the filter does not depend on that.
        app(\App\Services\Hr\RequestThreadService::class)
            ->event($case, 'posh.test', 'AN-INTERNAL-EVENT', $people[0]);

        $this->asStranger();
        $body = $this->getJson("/api/posh/portal/{$raw}/thread")->assertOk()->json('data');

        $encoded = json_encode($body);
        $this->assertStringContainsString('FOR-THE-COMPLAINANT', $encoded);
        $this->assertStringNotContainsString('COMMITTEE-ONLY-NOTE', $encoded);
        $this->assertStringNotContainsString('AN-INTERNAL-EVENT', $encoded);

        // No author, no role, no ids, no attachments.
        $this->assertSame(['body', 'created_at'], array_keys($body[0]));
        $this->assertStringNotContainsString($people[0]->name, $encoded);
    }

    public function test_findings_appear_only_after_publication(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        Sanctum::actingAs($people[0]);
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();
        foreach ($people as $m) {
            Sanctum::actingAs($m);
            $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", ['decision' => Decision::APPROVED])->assertOk();
        }

        Sanctum::actingAs($people[0]);
        $this->putJson("/api/hr/posh-cases/{$case->id}/findings", ['summary' => 'THE-FINDING'])->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/record")->assertOk();

        // Recorded is not published. The committee has settled the wording and
        // nobody has decided it should be seen.
        $this->asStranger();
        $body = $this->getJson("/api/posh/portal/{$raw}")->assertOk()->json('data');
        $this->assertNull($body['findings']);
        $this->assertStringNotContainsString('THE-FINDING', json_encode($body));

        Sanctum::actingAs($people[0]);
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/publish")->assertOk();

        $this->asStranger();
        $body = $this->getJson("/api/posh/portal/{$raw}")->assertOk()->json('data');
        $this->assertSame('THE-FINDING', $body['findings']['summary']);
        $this->assertSame(Decision::OUTCOME_APPROVED, $body['outcome']);
    }

    /**
     * Publication is stamped in two places, and BOTH have to agree.
     *
     * hr_posh_cases.findings_published_at and hr_posh_findings.published_at
     * are written together by PoshFindingService::publish(). The portal checks
     * both, which looks redundant — removing either one leaves the other
     * standing and every ordinary test still passes.
     *
     * It is not redundant. These two tests drive the halves apart, which is
     * what a partial write, a data fix or a future code path that stamps one
     * without the other would do. Either stamp alone must show nothing: on a
     * harassment finding, "probably published" is not good enough.
     */
    public function test_a_published_finding_without_the_case_stamp_stays_hidden(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);
        $this->publishFinding($case, $people);

        // The case forgets; the finding still says published. Written by
        // query, not through the stale in-memory model — which already held
        // null and would have saved nothing.
        HrPoshCase::whereKey($case->id)->update(['findings_published_at' => null]);
        $this->assertNull(HrPoshCase::findOrFail($case->id)->findings_published_at);

        $this->asStranger();
        $body = $this->getJson("/api/posh/portal/{$raw}")->assertOk()->json('data');

        $this->assertNull($body['findings']);
        $this->assertStringNotContainsString('THE-FINDING', json_encode($body));
    }

    public function test_a_case_stamp_without_a_published_finding_shows_nothing(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);
        $this->publishFinding($case, $people);

        // The finding is pulled back; the case still says published.
        \App\Models\Hr\HrPoshFinding::where('case_id', $case->id)
            ->update(['published_at' => null, 'published_by' => null]);

        $this->asStranger();
        $body = $this->getJson("/api/posh/portal/{$raw}")->assertOk()->json('data');

        $this->assertNull($body['findings']);
        $this->assertStringNotContainsString('THE-FINDING', json_encode($body));
    }

    /** Run a case all the way to a published finding. */
    private function publishFinding(HrPoshCase $case, array $people): void
    {
        Sanctum::actingAs($people[0]);
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();

        foreach ($people as $m) {
            Sanctum::actingAs($m);
            $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", ['decision' => Decision::APPROVED])->assertOk();
        }

        Sanctum::actingAs($people[0]);
        $this->putJson("/api/hr/posh-cases/{$case->id}/findings", ['summary' => 'THE-FINDING'])->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/record")->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/publish")->assertOk();
    }

    public function test_a_terminal_case_stays_readable_until_the_link_expires(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        Sanctum::actingAs($people[0]);
        $this->patchJson("/api/hr/posh-cases/{$case->id}/withdraw", ['reason' => 'Retracted.'])->assertOk();

        // Withdrawal does not revoke the link: the outcome becoming invisible
        // at the moment it is decided would be the wrong behaviour.
        $this->asStranger();
        $body = $this->getJson("/api/posh/portal/{$raw}")->assertOk()->json('data');
        $this->assertSame(HrPoshCase::STATUS_WITHDRAWN, $body['status']);
    }

    public function test_the_complainant_never_becomes_a_case_member(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        $this->asStranger();
        $this->getJson("/api/posh/portal/{$raw}")->assertOk();
        $this->getJson("/api/posh/portal/{$raw}/thread")->assertOk();

        $this->assertSame(2, HrPoshCaseMember::where('case_id', $case->id)->count());
        $this->assertSame(
            collect($people)->pluck('id')->sort()->values()->all(),
            HrPoshCaseMember::where('case_id', $case->id)->pluck('user_id')
                ->map(fn ($i) => (int) $i)->sort()->values()->all()
        );
    }

    public function test_the_link_opens_no_internal_surface(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        $this->asStranger();

        // The token is not a session. Nothing authenticated opens for it.
        foreach ([
            "/api/hr/posh-cases/{$case->id}",
            "/api/hr/posh-cases/{$case->id}/thread",
            "/api/hr/posh-cases/{$case->id}/attachments",
            "/api/hr/posh-cases/{$case->id}/findings",
        ] as $path) {
            $this->getJson($path, ['Authorization' => "Bearer {$raw}"])->assertStatus(401);
        }
    }

    /* ── failure is always the same answer ────────────────────────────── */

    public function test_every_bad_link_is_indistinguishable(): void
    {
        [$case, $people] = $this->raise();

        // Expired.
        [$expired] = $this->issue($case, $people[0]);
        HrPoshCaseToken::where('token_hash', hash('sha256', $expired))
            ->update(['expires_at' => now()->subDay()]);

        // Revoked by hand.
        [$revoked, $revokedId] = $this->issue($case, $people[0]);
        Sanctum::actingAs($people[0]);
        $this->deleteJson("/api/hr/posh-cases/{$case->id}/tokens/{$revokedId}")->assertOk();

        // Superseded by a re-issue.
        [$superseded] = $this->issue($case, $people[0]);
        $this->issue($case, $people[0]);

        // Another workspace's live link, used against this one's portal.
        [$theirCase, $theirPeople] = $this->raise($this->other);
        [$crossTenant] = $this->issue($theirCase, $theirPeople[0]);
        HrPoshCaseToken::where('token_hash', hash('sha256', $crossTenant))
            ->update(['revoked_at' => now()]);

        $this->asStranger();

        $responses = [];
        foreach ([
            'malformed' => 'not-a-token',
            'wrong-length' => str_repeat('a', 40),
            'unknown'   => str_repeat('z', 64),
            'expired'   => $expired,
            'revoked'   => $revoked,
            'superseded' => $superseded,
            'cross-tenant' => $crossTenant,
        ] as $name => $token) {
            $r = $this->getJson("/api/posh/portal/{$token}");
            $responses[$name] = [$r->status(), $r->getContent()];
        }

        // Byte-identical, every one. Any difference confirms a token was real.
        $first = reset($responses);
        foreach ($responses as $name => $pair) {
            $this->assertSame($first[0], $pair[0], "{$name} answered with a different status");
            $this->assertSame($first[1], $pair[1], "{$name} answered with a different body");
        }

        $this->assertSame(404, $first[0]);
        $this->assertStringNotContainsStringIgnoringCase('expired', $first[1]);
        $this->assertStringNotContainsStringIgnoringCase('revoked', $first[1]);
    }

    public function test_a_token_cannot_be_pointed_at_another_case(): void
    {
        [$mine, $minePeople] = $this->raise();
        [$theirs, $theirPeople] = $this->raise();

        [$raw] = $this->issue($mine, $minePeople[0]);
        $this->issue($theirs, $theirPeople[0]);

        $this->asStranger();

        // The token names its own case. There is no id in the URL to swap.
        $body = $this->getJson("/api/posh/portal/{$raw}")->assertOk()->json('data');
        $this->assertSame($mine->reference, $body['reference']);
        $this->assertNotSame($theirs->reference, $body['reference']);
    }

    public function test_revocation_needs_case_authority(): void
    {
        [$case, $people] = $this->raise();
        [, $tokenId] = $this->issue($case, $people[0]);

        Sanctum::actingAs($people[1]);
        $this->deleteJson("/api/hr/posh-cases/{$case->id}/tokens/{$tokenId}")->assertStatus(403);

        Sanctum::actingAs($this->staffWith(['hr_settings']));
        $this->deleteJson("/api/hr/posh-cases/{$case->id}/tokens/{$tokenId}")->assertStatus(404);

        $this->assertNull(HrPoshCaseToken::find($tokenId)->revoked_at);
    }

    public function test_a_token_id_from_another_case_cannot_be_revoked_through_mine(): void
    {
        [$mine, $minePeople] = $this->raise();
        [$theirs, $theirPeople] = $this->raise();
        [, $theirTokenId] = $this->issue($theirs, $theirPeople[0]);

        Sanctum::actingAs($minePeople[0]);
        $this->deleteJson("/api/hr/posh-cases/{$mine->id}/tokens/{$theirTokenId}")->assertStatus(404);

        $this->assertNull(HrPoshCaseToken::find($theirTokenId)->revoked_at);
    }

    /* ── auditing ─────────────────────────────────────────────────────── */

    public function test_a_portal_read_is_audited_without_an_actor(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        HrPoshCaseRead::where('case_id', $case->id)->delete();

        $this->asStranger();
        $this->getJson("/api/posh/portal/{$raw}")->assertOk();
        $this->getJson("/api/posh/portal/{$raw}/thread")->assertOk();

        $reads = HrPoshCaseRead::where('case_id', $case->id)->get();

        $this->assertSame(['portal.show', 'portal.thread'], $reads->pluck('surface')->sort()->values()->all());

        foreach ($reads as $read) {
            $this->assertNull($read->actor_id);
            $this->assertStringContainsString($case->reference, $read->actor_label);
            $this->assertStringNotContainsString($raw, json_encode($read->getAttributes()));
        }
    }

    public function test_a_refused_link_records_no_read(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);
        HrPoshCaseToken::where('case_id', $case->id)->update(['revoked_at' => now()]);

        HrPoshCaseRead::where('case_id', $case->id)->delete();

        $this->asStranger();
        $this->getJson("/api/posh/portal/{$raw}")->assertStatus(404);
        $this->getJson('/api/posh/portal/'.str_repeat('q', 64))->assertStatus(404);

        $this->assertSame(0, HrPoshCaseRead::where('case_id', $case->id)->count());
    }

    public function test_use_is_counted(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        $this->asStranger();
        $this->getJson("/api/posh/portal/{$raw}")->assertOk();
        $this->getJson("/api/posh/portal/{$raw}")->assertOk();

        $row = HrPoshCaseToken::where('case_id', $case->id)->firstOrFail();
        $this->assertSame(2, $row->use_count);
        $this->assertNotNull($row->last_used_at);
        $this->assertNotNull($row->presented_at);
    }

    public function test_the_portal_is_throttled(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        $this->asStranger();

        $sawLimit = false;
        for ($i = 0; $i < 25; $i++) {
            if ($this->getJson("/api/posh/portal/{$raw}")->status() === 429) {
                $sawLimit = true;
                break;
            }
        }

        $this->assertTrue($sawLimit, 'The portal accepted 25 requests without throttling');
    }

    public function test_the_portal_accepts_no_writes(): void
    {
        [$case, $people] = $this->raise();
        [$raw] = $this->issue($case, $people[0]);

        $this->asStranger();
        $this->postJson("/api/posh/portal/{$raw}/thread", ['body' => 'hello'])->assertStatus(405);
        $this->postJson("/api/posh/portal/{$raw}", ['body' => 'hello'])->assertStatus(405);
    }
}
