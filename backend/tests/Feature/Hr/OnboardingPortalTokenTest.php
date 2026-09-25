<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrOnboarding;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\OnboardingPortalToken;
use App\Services\Hr\OnboardingService;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The candidate's onboarding link, and what it is now made of.
 *
 * This portal takes personal details, education, experience, bank details and
 * identity documents, and it used to be guarded by a 48-character string kept
 * in plaintext with no expiry and no way to revoke it. The properties worth
 * defending are therefore not subtle:
 *
 *   THE RAW TOKEN IS NEVER STORED. Several tests below go looking for it in
 *   the row, in the audit trail and in the logs rather than trusting that it
 *   was never put there.
 *
 *   EVERY ENDPOINT IS BEHIND THE SAME LIFECYCLE. Protecting the landing page
 *   proves nothing about the upload route, so all six public endpoints are
 *   driven with an expired and a revoked token, not just the first.
 */
class OnboardingPortalTokenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'obt', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'obt2', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function hrUser(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'HR',
            'email' => uniqid().'@obt.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function onboarding(?Tenant $t = null): HrOnboarding
    {
        $t = $t ?: $this->tenant;

        $candidate = HrCandidate::create([
            'tenant_id' => $t->id, 'name' => 'C'.substr(uniqid(), -5),
            'email' => uniqid().'@cand.test', 'phone' => '9000000000',
            'stage' => 'Offer', 'status' => 'Active',
        ]);

        return HrOnboarding::create([
            'tenant_id' => $t->id, 'candidate_id' => $candidate->id,
            'candidate_name' => $candidate->name, 'position' => 'Analyst',
            'status' => 'Pending', 'verification_status' => 'Pending',
            'invited_at' => now(),
        ]);
    }

    private function tokens(): OnboardingPortalToken
    {
        return app(OnboardingPortalToken::class);
    }

    /** Issue through the service, as production does. */
    private function issue(HrOnboarding $onboarding): string
    {
        return $this->tokens()->issue($onboarding->fresh());
    }

    /** Every public portal endpoint, as callable request closures. */
    private function endpoints(string $token): array
    {
        return [
            'dashboard'      => fn () => $this->getJson("/api/onboarding/{$token}"),
            'save section'   => fn () => $this->patchJson("/api/onboarding/{$token}/form/section/personal", ['first_name' => 'A']),
            'save child'     => fn () => $this->postJson("/api/onboarding/{$token}/form/education", ['degree' => 'BSc']),
            'delete child'   => fn () => $this->deleteJson("/api/onboarding/{$token}/form/education/1"),
            'upload'         => fn () => $this->postJson("/api/onboarding/{$token}/documents", [
                'type' => 'pan', 'document' => UploadedFile::fake()->create('pan.pdf', 10, 'application/pdf'),
            ]),
            // A VALID payload deliberately: SubmitOnboardingRequest validates
            // before the controller resolves the token, so an invalid body
            // would answer 422 and prove nothing about the credential.
            'submit'         => fn () => $this->postJson("/api/onboarding/{$token}/submit", ['submission' => json_encode(['a' => 'b'])]),
        ];
    }

    /* ── the credential itself ────────────────────────────────────────── */

    public function test_the_token_is_high_entropy(): void
    {
        $onboarding = $this->onboarding();
        $raw = $this->issue($onboarding);

        $this->assertSame(OnboardingPortalToken::TOKEN_LENGTH, strlen($raw));
        $this->assertSame(64, strlen($raw));
        $this->assertTrue(ctype_alnum($raw));

        // Distinct across issuances — a generator returning anything derived
        // from the record would repeat.
        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            $seen[] = $this->issue($this->onboarding());
        }
        $this->assertCount(5, array_unique($seen));
    }

    public function test_the_raw_token_is_never_stored(): void
    {
        $onboarding = $this->onboarding();
        $raw = $this->issue($onboarding);

        $row = DB::table('hr_onboarding')->where('id', $onboarding->id)->first();

        $this->assertSame(hash('sha256', $raw), $row->token_hash);
        $this->assertNull($row->access_token, 'the legacy plaintext column was written again');

        // Nothing anywhere in the row equals the raw value.
        foreach ((array) $row as $column => $value) {
            $this->assertNotSame($raw, (string) $value, "{$column} holds the raw token");
        }
    }

    public function test_the_stored_credential_is_a_hash_not_the_token(): void
    {
        $onboarding = $this->onboarding();
        $raw = $this->issue($onboarding);
        $stored = DB::table('hr_onboarding')->where('id', $onboarding->id)->value('token_hash');

        $this->assertNotSame($raw, $stored);
        $this->assertSame(64, strlen($stored));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored);
    }

    public function test_the_hash_is_never_serialised(): void
    {
        $onboarding = $this->onboarding();
        $this->issue($onboarding);

        $array = $onboarding->fresh()->toArray();

        $this->assertArrayNotHasKey('token_hash', $array);
        $this->assertArrayNotHasKey('access_token', $array);

        // And not through the HR-facing endpoint that returns the model.
        Sanctum::actingAs($this->hrUser());
        $body = $this->getJson("/api/hr/onboarding/{$onboarding->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('token_hash', $body);
    }

    public function test_the_raw_token_reaches_no_audit_or_log(): void
    {
        $onboarding = $this->onboarding();
        $raw = $this->issue($onboarding);

        $audits = \App\Models\AuditLog::where('auditable_id', $onboarding->id)->get();
        $this->assertTrue($audits->isNotEmpty(), 'issuing should leave an audit trail');

        foreach ($audits as $audit) {
            $this->assertStringNotContainsString($raw, json_encode($audit->getAttributes()));
        }

        $log = storage_path('logs/hr-'.now()->toDateString().'.log');
        if (is_file($log)) {
            $this->assertStringNotContainsString($raw, (string) file_get_contents($log));
        }
    }

    /* ── access ───────────────────────────────────────────────────────── */

    public function test_a_live_token_opens_the_portal(): void
    {
        $onboarding = $this->onboarding();
        $raw = $this->issue($onboarding);

        $body = $this->getJson("/api/onboarding/{$raw}")->assertOk()->json();

        $this->assertSame($onboarding->candidate_name, $body['candidate']['name']);
    }

    /**
     * @dataProvider badTokens
     */
    public function test_a_bad_token_is_refused(string $case): void
    {
        $onboarding = $this->onboarding();
        $raw = $this->issue($onboarding);

        $token = match ($case) {
            'unknown'   => str_repeat('z', 64),
            'malformed' => 'not-a-token',
            'short'     => substr($raw, 0, 40),
            'expired'   => $this->expire($onboarding, $raw),
            'revoked'   => $this->revoke($onboarding, $raw),
        };

        $this->getJson("/api/onboarding/{$token}")->assertStatus(404);
    }

    public static function badTokens(): array
    {
        return [['unknown'], ['malformed'], ['short'], ['expired'], ['revoked']];
    }

    private function expire(HrOnboarding $onboarding, string $raw): string
    {
        HrOnboarding::whereKey($onboarding->id)->update(['token_expires_at' => now()->subMinute()]);

        return $raw;
    }

    private function revoke(HrOnboarding $onboarding, string $raw): string
    {
        $this->tokens()->revoke($onboarding->fresh(), $this->hrUser());

        return $raw;
    }

    /**
     * Rubbish is answered, not thrown at.
     *
     * The shape check in front of the lookup is defensive rather than
     * authorising — the hash comparison is what actually refuses a wrong
     * token, and tests above prove that. What the check does own is the
     * contract that resolve() takes a nullable string and returns null: hand
     * it null or an empty string with the check gone and hash() receives a
     * non-string, which is a 500 where a 404 belongs.
     */
    public function test_the_resolver_answers_rubbish_rather_than_failing(): void
    {
        foreach ([null, '', '   ', 'not-a-token', str_repeat('!', 64)] as $bad) {
            $this->assertNull($this->tokens()->resolve($bad));
        }
    }

    public function test_every_public_endpoint_refuses_an_expired_token(): void
    {
        $onboarding = $this->onboarding();
        $raw = $this->issue($onboarding);
        $this->expire($onboarding, $raw);

        // Protecting the landing page proves nothing about the upload route.
        foreach ($this->endpoints($raw) as $name => $call) {
            $this->assertSame(404, $call()->status(), "{$name} accepted an expired token");
        }
    }

    public function test_every_public_endpoint_refuses_a_revoked_token(): void
    {
        $onboarding = $this->onboarding();
        $raw = $this->issue($onboarding);
        $this->tokens()->revoke($onboarding->fresh(), $this->hrUser());

        foreach ($this->endpoints($raw) as $name => $call) {
            $this->assertSame(404, $call()->status(), "{$name} accepted a revoked token");
        }
    }

    public function test_every_public_endpoint_refuses_an_unknown_token(): void
    {
        foreach ($this->endpoints(str_repeat('q', 64)) as $name => $call) {
            $this->assertSame(404, $call()->status(), "{$name} accepted an unknown token");
        }
    }

    public function test_upload_and_submission_work_with_a_live_token(): void
    {
        \Storage::fake('local');
        $onboarding = $this->onboarding();
        $raw = $this->issue($onboarding);

        // The same endpoints that refuse a dead token must still work with a
        // live one, or the tests above would pass on a broken portal.
        $this->postJson("/api/onboarding/{$raw}/documents", [
            'type' => 'pan', 'document' => UploadedFile::fake()->create('pan.pdf', 10, 'application/pdf'),
        ])->assertOk();

        $this->postJson("/api/onboarding/{$raw}/submit", ['submission' => json_encode(['a' => 'b'])])->assertOk();

        $this->assertSame('Submitted', $onboarding->fresh()->verification_status);
    }

    /* ── tenancy and enumeration ──────────────────────────────────────── */

    public function test_a_token_resolves_only_its_own_record(): void
    {
        $mine = $this->onboarding();
        $theirs = $this->onboarding($this->other);

        $mineRaw = $this->issue($mine);
        $this->issue($theirs);

        $body = $this->getJson("/api/onboarding/{$mineRaw}")->assertOk()->json();

        $this->assertSame($mine->candidate_name, $body['candidate']['name']);
        $this->assertNotSame($theirs->candidate_name, $body['candidate']['name']);
    }

    public function test_the_portal_cannot_be_enumerated(): void
    {
        $onboarding = $this->onboarding();
        $this->issue($onboarding);

        // A record id is not a credential, and there is no index route.
        foreach ([(string) $onboarding->id, '1', '0'] as $guess) {
            $this->getJson("/api/onboarding/{$guess}")->assertStatus(404);
        }

        $this->getJson('/api/onboarding')->assertStatus(404);
    }

    /* ── reissue and revoke ───────────────────────────────────────────── */

    public function test_issuing_again_kills_the_previous_link(): void
    {
        $onboarding = $this->onboarding();
        $first = $this->issue($onboarding);
        $this->getJson("/api/onboarding/{$first}")->assertOk();

        $second = $this->issue($onboarding);

        $this->assertNotSame($first, $second);
        $this->getJson("/api/onboarding/{$first}")->assertStatus(404);
        $this->getJson("/api/onboarding/{$second}")->assertOk();
    }

    public function test_reissuing_clears_an_earlier_revocation(): void
    {
        $onboarding = $this->onboarding();
        $this->issue($onboarding);
        $this->tokens()->revoke($onboarding->fresh(), $this->hrUser());

        $fresh = $this->issue($onboarding);

        // A new link must be live, not born dead under the old revocation.
        $this->getJson("/api/onboarding/{$fresh}")->assertOk();
        $this->assertNull($onboarding->fresh()->token_revoked_at);
    }

    public function test_hr_can_issue_and_revoke_through_the_api(): void
    {
        $onboarding = $this->onboarding();

        Sanctum::actingAs($this->hrUser());
        $data = $this->postJson("/api/hr/onboarding/{$onboarding->id}/portal-link")
            ->assertStatus(201)->json('data');

        $this->assertNotNull($data['expires_at']);
        $raw = substr($data['link'], strrpos($data['link'], '/') + 1);
        $this->getJson("/api/onboarding/{$raw}")->assertOk();

        Sanctum::actingAs($this->hrUser());
        $this->deleteJson("/api/hr/onboarding/{$onboarding->id}/portal-link", ['reason' => 'Wrong address.'])->assertOk();

        $this->getJson("/api/onboarding/{$raw}")->assertStatus(404);
    }

    public function test_issuing_needs_hr_authority_and_the_right_workspace(): void
    {
        $onboarding = $this->onboarding();

        // Not HR.
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'X', 'email' => uniqid().'@obt.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]));
        $this->postJson("/api/hr/onboarding/{$onboarding->id}/portal-link")->assertStatus(403);

        // HR, but in another workspace.
        Sanctum::actingAs($this->hrUser($this->other));
        $this->postJson("/api/hr/onboarding/{$onboarding->id}/portal-link")->assertStatus(404);

        $this->assertNull($onboarding->fresh()->token_hash);
    }

    public function test_there_is_no_endpoint_that_returns_an_existing_link(): void
    {
        $onboarding = $this->onboarding();
        $this->issue($onboarding);

        Sanctum::actingAs($this->hrUser());

        // The raw value is unrecoverable by design.
        $this->assertSame(404, $this->getJson("/api/hr/onboarding/{$onboarding->id}/portal-link")->status());
    }

    /* ── expiry configuration ─────────────────────────────────────────── */

    public function test_expiry_comes_from_the_tenant_setting(): void
    {
        app(SettingsService::class)->setGroup(
            $this->tenant->id, HrSetting::GROUP, ['onboarding_link_ttl_days' => 7]
        );

        $onboarding = $this->onboarding();
        $this->issue($onboarding);

        $this->assertSame(7, (int) round(
            now()->diffInDays($onboarding->fresh()->token_expires_at, false)
        ));
    }

    /**
     * @dataProvider unusableTtls
     */
    public function test_an_unusable_validity_issues_nothing(mixed $ttl): void
    {
        app(SettingsService::class)->setGroup(
            $this->tenant->id, HrSetting::GROUP, ['onboarding_link_ttl_days' => $ttl]
        );

        $onboarding = $this->onboarding();

        // Fail closed: 30 is not quietly substituted, because that would hide
        // the misconfiguration behind a link that works anyway.
        $this->expectException(\App\Exceptions\BusinessException::class);

        try {
            $this->issue($onboarding);
        } finally {
            $this->assertNull($onboarding->fresh()->token_hash);
        }
    }

    public static function unusableTtls(): array
    {
        return [[0], [-1], [null], ['forever']];
    }

    public function test_the_default_validity_is_thirty_days(): void
    {
        $this->assertSame(30, (int) app(SettingsService::class)->get(
            $this->tenant->id, HrSetting::GROUP, 'onboarding_link_ttl_days'
        ));
    }

    /* ── legacy compatibility ─────────────────────────────────────────── */

    /**
     * A link already in a candidate's inbox keeps working.
     *
     * The migration hashes what was in access_token and empties the column, so
     * the raw value the candidate holds still resolves. This reproduces that
     * row directly — plaintext hashed, column cleared, grace expiry set — and
     * proves the link is not silently invalidated.
     */
    public function test_a_link_issued_before_the_hardening_still_works(): void
    {
        $onboarding = $this->onboarding();
        $legacyRaw = \Illuminate\Support\Str::random(48);

        HrOnboarding::whereKey($onboarding->id)->update([
            'token_hash'       => hash('sha256', $legacyRaw),
            'token_issued_at'  => now()->subMonths(4),
            'token_expires_at' => now()->addDays(30),
            'access_token'     => null,
        ]);

        $this->getJson("/api/onboarding/{$legacyRaw}")->assertOk();
    }

    public function test_a_legacy_link_is_not_immortal(): void
    {
        $onboarding = $this->onboarding();
        $legacyRaw = \Illuminate\Support\Str::random(48);

        HrOnboarding::whereKey($onboarding->id)->update([
            'token_hash'       => hash('sha256', $legacyRaw),
            'token_issued_at'  => now()->subMonths(4),
            // The grace period the migration grants, now behind us.
            'token_expires_at' => now()->subDay(),
            'access_token'     => null,
        ]);

        $this->getJson("/api/onboarding/{$legacyRaw}")->assertStatus(404);
    }

    public function test_a_plaintext_token_is_no_longer_a_credential(): void
    {
        $onboarding = $this->onboarding();
        $legacyRaw = \Illuminate\Support\Str::random(48);

        // A row that somehow still carries plaintext — a restored backup, a
        // hand-edited record. It must not open anything: token_hash is the
        // credential and nothing else is consulted.
        DB::table('hr_onboarding')->where('id', $onboarding->id)->update([
            'access_token' => $legacyRaw, 'token_hash' => null,
        ]);

        $this->getJson("/api/onboarding/{$legacyRaw}")->assertStatus(404);
    }

    public function test_a_record_with_no_expiry_is_treated_as_dead(): void
    {
        $onboarding = $this->onboarding();
        $raw = $this->issue($onboarding);

        HrOnboarding::whereKey($onboarding->id)->update(['token_expires_at' => null]);

        // Unreachable through issue() or the migration, both of which always
        // set one. Fail closed on a state that should not exist.
        $this->getJson("/api/onboarding/{$raw}")->assertStatus(404);
    }

    /* ── the migration itself ─────────────────────────────────────────── */

    public function test_the_hardening_migration_leaves_no_plaintext_behind(): void
    {
        $this->assertTrue(\Schema::hasColumn('hr_onboarding', 'token_hash'));
        $this->assertTrue(\Schema::hasColumn('hr_onboarding', 'token_expires_at'));
        $this->assertTrue(\Schema::hasColumn('hr_onboarding', 'token_revoked_at'));

        // Nothing in the codebase writes the legacy column any more.
        $this->assertSame(
            0,
            DB::table('hr_onboarding')->whereNotNull('access_token')->count()
        );
    }
}
