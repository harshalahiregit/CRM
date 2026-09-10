<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The ways a doctor comes by a login.
 *
 * There was exactly one: an admin created the account and the SYSTEM SHOWED THE
 * ADMIN THE PASSWORD. For most roles that is merely untidy. For this one it
 * undermines the module: every certificate carries the doctor's licence number,
 * and if the admin knows the password then "Dr Rao signed this" is not a claim
 * that survives being questioned.
 *
 * Three routes now, and the tests below pin what separates them:
 *
 *  - INVITE (the default): the doctor sets their own password from a one-time
 *    link. Nobody else ever learns it.
 *  - PASSWORD (kept on purpose): for sites where email is unreliable, which on
 *    a construction site it often is.
 *  - PROMOTE: an existing internal user becomes a doctor, keeping the login
 *    they already have.
 */
class DoctorCredentialRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
        Mail::fake();
    }

    private function admin(): User
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin Ada', 'role' => 'admin',
            'email' => 'admin-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(array $extra = []): array
    {
        return [
            'name' => 'Dr Rao', 'email' => 'rao-'.Str::random(5).'@t.local',
            'license_no' => 'MH-1', ...$extra,
        ];
    }

    /* ── Invite: the default ────────────────────────────────────────────── */

    public function test_creating_a_doctor_invites_them_and_tells_nobody_the_password(): void
    {
        $this->admin();

        $body = $this->postJson('/api/medical/doctors', $this->payload())->assertCreated()->json();

        // The whole point: the admin is not handed a password.
        $this->assertArrayNotHasKey('temporary_password', $body);
        $this->assertTrue($body['invited']);

        $email = $body['data']['user']['email'];
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $email]);
    }

    public function test_the_invite_token_is_stored_hashed(): void
    {
        // A readable token in the table is a password reset waiting to be lifted
        // by anyone who can read the database.
        $this->admin();
        $body = $this->postJson('/api/medical/doctors', $this->payload())->assertCreated()->json();

        $row = DB::table('password_reset_tokens')->where('email', $body['data']['user']['email'])->first();

        $this->assertNotNull($row);
        $this->assertStringStartsWith('$2y$', $row->token, 'the token is hashed, not stored as issued');
    }

    public function test_the_doctor_can_sign_in_with_the_password_they_set(): void
    {
        // End to end: invited, sets their own password, signs in with it.
        $this->admin();
        $body  = $this->postJson('/api/medical/doctors', $this->payload())->assertCreated()->json();
        $email = $body['data']['user']['email'];

        // The link carries the raw token; the table holds only its hash, so the
        // test reissues one the same way the mail does.
        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => bcrypt($token), 'created_at' => now()],
        );

        $this->postJson('/api/auth/set-password', [
            'email' => $email, 'token' => $token,
            'password' => 'ChosenByThem1', 'password_confirmation' => 'ChosenByThem1',
        ])->assertOk();

        app('auth')->forgetGuards();
        $this->postJson('/api/auth/login', [
            'email' => $email, 'password' => 'ChosenByThem1', 'role' => 'doctor',
        ])->assertOk();
    }

    /* ── Password: kept for sites with no reliable mail ─────────────────── */

    public function test_asking_for_a_password_returns_one_once_and_sends_no_invite(): void
    {
        $this->admin();

        $body = $this->postJson('/api/medical/doctors', $this->payload(['delivery' => 'password']))
            ->assertCreated()->json();

        $this->assertNotEmpty($body['temporary_password']);
        $this->assertFalse($body['invited']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $body['data']['user']['email']]);
    }

    public function test_supplying_a_password_is_itself_a_request_for_that_route(): void
    {
        // An admin who typed a password means to hand it over; making them also
        // pick a delivery mode would be asking the same question twice.
        $this->admin();

        $body = $this->postJson('/api/medical/doctors', $this->payload(['password' => 'TypedByAdmin1']))
            ->assertCreated()->json();

        $this->assertSame('TypedByAdmin1', $body['temporary_password']);
        $this->assertFalse($body['invited']);
    }

    /* ── Re-inviting ────────────────────────────────────────────────────── */

    public function test_an_invitation_can_be_sent_again(): void
    {
        // Mail fails for reasons that have nothing to do with this system.
        $this->admin();
        $id = $this->postJson('/api/medical/doctors', $this->payload(['delivery' => 'password']))
            ->assertCreated()->json('data.id');

        $this->postJson("/api/medical/doctors/{$id}/invite")->assertOk()->assertJson(['invited' => true]);
    }

    public function test_a_deactivated_doctor_is_not_invited_back_in(): void
    {
        $this->admin();
        $id = $this->postJson('/api/medical/doctors', $this->payload())->assertCreated()->json('data.id');

        $this->deleteJson("/api/medical/doctors/{$id}")->assertOk();

        $this->postJson("/api/medical/doctors/{$id}/invite")->assertStatus(422);
    }

    /* ── Promote: the person who already has a login ────────────────────── */

    public function test_an_existing_staff_member_can_be_made_a_doctor(): void
    {
        // This was impossible: creating needed an unused email address, so a
        // company doctor already on staff had to have a second account.
        $this->admin();
        $staff = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Dr Meera', 'role' => 'staff',
            'email' => 'meera@t.local', 'password' => bcrypt('TheirOwn1'), 'status' => 'active',
        ]);

        $this->postJson('/api/medical/doctors/promote', [
            'user_id' => $staff->id, 'license_no' => 'MH-9',
        ])->assertCreated();

        $this->assertSame('doctor', $staff->fresh()->role);

        // No new password, and the one they had still works.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'meera@t.local']);
        app('auth')->forgetGuards();
        $this->postJson('/api/auth/login', [
            'email' => 'meera@t.local', 'password' => 'TheirOwn1', 'role' => 'doctor',
        ])->assertOk();
    }

    public function test_promoting_an_admin_leaves_their_admin_access_alone(): void
    {
        // Taking the role away to make somebody a doctor would quietly remove
        // their access to everything else they do.
        $actor = $this->admin();
        $other = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Dr Admin', 'role' => 'admin',
            'email' => 'dradmin@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Sanctum::actingAs($actor);

        $this->postJson('/api/medical/doctors/promote', [
            'user_id' => $other->id, 'license_no' => 'MH-7',
        ])->assertCreated();

        $this->assertSame('admin', $other->fresh()->role);
        $this->assertDatabaseHas('medical_doctor_profiles', ['user_id' => $other->id]);
    }

    public function test_the_same_person_cannot_be_made_a_doctor_twice(): void
    {
        $this->admin();
        $staff = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Dr Meera', 'role' => 'staff',
            'email' => 'meera2@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->postJson('/api/medical/doctors/promote', ['user_id' => $staff->id, 'license_no' => 'MH-9'])->assertCreated();
        $this->postJson('/api/medical/doctors/promote', ['user_id' => $staff->id, 'license_no' => 'MH-9'])->assertStatus(422);
    }

    public function test_a_portal_login_cannot_be_promoted_into_the_workspace(): void
    {
        // Vendor and client logins belong to their own registers; making one a
        // doctor would put an outsider inside the company.
        $this->admin();
        $vendor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor Contact', 'role' => 'third_party_vendor',
            'email' => 'vendor@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->postJson('/api/medical/doctors/promote', [
            'user_id' => $vendor->id, 'license_no' => 'MH-9',
        ])->assertStatus(422);
    }

    public function test_somebody_from_another_workspace_cannot_be_promoted(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $theirs = User::create([
            'tenant_id' => 2, 'name' => 'Theirs', 'role' => 'staff',
            'email' => 'theirs@t2.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $this->admin();

        $this->postJson('/api/medical/doctors/promote', [
            'user_id' => $theirs->id, 'license_no' => 'MH-9',
        ])->assertNotFound();
    }

    public function test_a_licence_number_is_required_on_every_route(): void
    {
        // It is printed on every certificate the doctor signs, so no route may
        // create one without it.
        $this->admin();
        $staff = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Dr Meera', 'role' => 'staff',
            'email' => 'meera3@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->postJson('/api/medical/doctors', ['name' => 'Dr Rao', 'email' => 'rao9@t.local'])->assertStatus(422);
        $this->postJson('/api/medical/doctors/promote', ['user_id' => $staff->id])->assertStatus(422);
    }
}
