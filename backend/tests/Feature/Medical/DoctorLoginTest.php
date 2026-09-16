<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A doctor account can actually be used.
 *
 * The reported fault: an admin created a doctor login successfully, then found
 * no way to sign in with it. The login form asks which role you are, and
 * "Doctor" was not among the options — and even if it had been, LoginRequest's
 * `in:` rule listed every role except this one, so the attempt would have been
 * refused. A whole portal was reachable only by a credential that could not be
 * presented.
 *
 * The rule these tests hold: **every role the app can create an account for is
 * a role the app can log in as** — and no more than those.
 */
class DoctorLoginTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const PASSWORD = 'doctor-password-1';

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role, string $status = 'active'): User
    {
        return User::create([
            'tenant_id' => self::TENANT,
            'name' => ucfirst($role),
            'email' => $role.'-'.Str::random(6).'@t.local',
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
            'status' => $status,
        ]);
    }

    public function test_a_doctor_can_sign_in(): void
    {
        $doctor = $this->user('doctor');

        $this->postJson('/api/auth/login', [
            'email' => $doctor->email,
            'password' => self::PASSWORD,
            'role' => 'doctor',
        ])->assertOk()
            ->assertJsonPath('data.user.role', 'doctor');
    }

    public function test_the_doctor_role_is_not_refused_by_the_login_rule(): void
    {
        // The precise failure: 'doctor' was absent from LoginRequest's in: list,
        // so the attempt died as a validation error on `role` before the
        // password was ever checked.
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@t.local',
            'password' => 'whatever',
            'role' => 'doctor',
        ])->assertJsonMissingValidationErrors('role');
    }

    public function test_a_doctor_reaches_the_doctor_portal(): void
    {
        $doctor = $this->user('doctor');

        $token = $this->postJson('/api/auth/login', [
            'email' => $doctor->email,
            'password' => self::PASSWORD,
            'role' => 'doctor',
        ])->assertOk()->json('data.access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/doctor/me')->assertOk();
    }

    public function test_the_doctor_token_cannot_open_the_admin_side(): void
    {
        // Widening the login rule must not widen what the account can do.
        $doctor = $this->user('doctor');

        $token = $this->postJson('/api/auth/login', [
            'email' => $doctor->email,
            'password' => self::PASSWORD,
            'role' => 'doctor',
        ])->assertOk()->json('data.access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/medical/doctors')->assertForbidden();
    }

    public function test_signing_in_under_the_wrong_role_still_fails(): void
    {
        // The role selector is part of the credential: a doctor's e-mail and
        // password presented as "admin" must not find the account.
        $doctor = $this->user('doctor');

        $this->postJson('/api/auth/login', [
            'email' => $doctor->email,
            'password' => self::PASSWORD,
            'role' => 'admin',
        ])->assertStatus(401);
    }

    public function test_a_purchase_vendor_is_still_refused_at_this_door(): void
    {
        // A PurchaseVendor is not a User and authenticates elsewhere. That door
        // stays shut — widening the list for doctors must not reopen it.
        $this->postJson('/api/auth/login', [
            'email' => 'pv@t.local',
            'password' => self::PASSWORD,
            'role' => 'vendor',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_an_inactive_doctor_cannot_sign_in(): void
    {
        // Deactivating a doctor must actually close their access — the doctor
        // directory deactivates rather than deletes, so this is the real switch.
        $doctor = $this->user('doctor', 'inactive');

        $this->postJson('/api/auth/login', [
            'email' => $doctor->email,
            'password' => self::PASSWORD,
            'role' => 'doctor',
        ])->assertStatus(403);
    }
    /* ── A mislaid password must not end the account ─────────────────────── */

    public function test_an_admin_can_issue_a_new_password(): void
    {
        // The password set at creation is shown once and only hashed after
        // that. Without a reset, an admin who did not write it down had no way
        // back in — the doctor was locked out permanently.
        $doctor = $this->user('doctor');
        $profile = MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT, 'user_id' => $doctor->id,
            'license_no' => 'MH-1', 'is_active' => true,
        ]);

        Sanctum::actingAs($this->user('admin'));
        $issued = $this->postJson("/api/medical/doctors/{$profile->id}/reset-password")
            ->assertOk()->json('temporary_password');

        $this->assertNotEmpty($issued);
        // The old password is dead, the new one works.
        $this->assertFalse(Hash::check(self::PASSWORD, $doctor->fresh()->password));
        $this->postJson('/api/auth/login', [
            'email' => $doctor->email, 'password' => $issued, 'role' => 'doctor',
        ])->assertOk();
    }

    public function test_a_reset_signs_the_doctor_out_everywhere(): void
    {
        // A reset that left live sessions open would not be a reset.
        $doctor = $this->user('doctor');
        $profile = MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT, 'user_id' => $doctor->id,
            'license_no' => 'MH-2', 'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $doctor->email, 'password' => self::PASSWORD, 'role' => 'doctor',
        ])->assertOk();
        $this->assertSame(1, $doctor->tokens()->count(), 'the doctor is signed in');

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/medical/doctors/{$profile->id}/reset-password")->assertOk();

        // Asserted on the token store rather than by replaying the bearer:
        // Sanctum::actingAs above takes precedence over an Authorization header,
        // so a follow-up request would answer as the admin, not the doctor.
        $this->assertSame(0, $doctor->tokens()->count(), 'every session the old password opened is gone');
    }

    public function test_only_an_admin_may_reset_a_doctors_password(): void
    {
        $doctor = $this->user('doctor');
        $profile = MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT, 'user_id' => $doctor->id,
            'license_no' => 'MH-3', 'is_active' => true,
        ]);

        // A doctor resetting their own — or a colleague's — credential would be
        // a privilege escalation, not a convenience.
        Sanctum::actingAs($doctor);
        $this->postJson("/api/medical/doctors/{$profile->id}/reset-password")->assertForbidden();
    }
}
