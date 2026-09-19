<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An internal doctor is CHOSEN, not retyped.
 *
 * Every medical record named its doctor in a free-text box — six of them across
 * the TPV and Purchase wizards, the vendor-detail quick-adds and the bulk CSV —
 * none connected to the doctor directory an admin had already filled in. The
 * same in-house doctor ended up as three different spellings, the licence
 * number was retyped (or mistyped) each time, and `doctor_user_id` was left
 * NULL by everything except the doctor's own portal.
 *
 * These tests hold two rules:
 *   1. Picking a doctor links the record to a REAL person and copies their
 *      licence from the directory.
 *   2. The typed name still works, so an outside doctor — or one not in the
 *      directory yet — is recorded exactly as before.
 */
class InternalDoctorPickerTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const PASSWORD = 'picker-password-1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = $this->user('admin');
    }

    private function user(string $role, string $status = 'active'): User
    {
        return User::create([
            'tenant_id' => self::TENANT,
            'name'      => ucfirst($role).' '.Str::random(4),
            'email'     => $role.'-'.Str::random(6).'@t.local',
            'password'  => Hash::make(self::PASSWORD),
            'role'      => $role,
            'status'    => $status,
        ]);
    }

    /** A doctor login plus the practising profile that makes them pickable. */
    private function doctor(array $profile = [], string $status = 'active'): User
    {
        $u = $this->user('doctor', $status);
        MedicalDoctorProfile::create(array_merge([
            'tenant_id'   => self::TENANT,
            'user_id'     => $u->id,
            'license_no'  => 'MH-'.Str::random(5),
            'council'     => 'MMC',
            'clinic_name' => 'City Clinic',
            'is_active'   => true,
        ], $profile));

        return $u;
    }

    private function worker(): TpvWorker
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'vendor_code' => 'V-'.Str::random(5), 'company_name' => 'Crane Hire',
        ]);
        $this->markOnboarded($vendor);

        $w = new TpvWorker;
        $w->forceFill([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'name' => 'Worker One', 'worker_code' => 'W-'.Str::random(5),
        ])->save();

        return $w;
    }

    /* ── The list ────────────────────────────────────────────────────────── */

    public function test_staff_can_read_the_doctor_options(): void
    {
        $doc = $this->doctor(['license_no' => 'MH-99887']);

        // Staff, not admin: whoever fills in a worker's medical has to be able
        // to name the doctor, and that is not only admins.
        Sanctum::actingAs($this->user('staff'));

        $rows = $this->getJson('/api/medical/doctor-options')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($doc->id, $rows[0]['user_id']);
        $this->assertSame('MH-99887', $rows[0]['license_no']);
        $this->assertTrue($rows[0]['is_signable']);
    }

    public function test_the_picker_does_not_leak_the_staff_directory(): void
    {
        $this->doctor();
        Sanctum::actingAs($this->user('staff'));

        $row = $this->getJson('/api/medical/doctor-options')->assertOk()->json('data.0');

        // A name, a licence and a clinic. Not an e-mail, phone, address,
        // signature path or account status.
        foreach (['email', 'phone', 'clinic_address', 'signature_path', 'stamp_path', 'status'] as $leak) {
            $this->assertArrayNotHasKey($leak, $row, "doctor-options must not expose {$leak}");
        }
    }

    public function test_a_deactivated_doctor_is_not_offered(): void
    {
        // Their name stays on certificates already signed; they must not be
        // attachable to a new one.
        $this->doctor(['is_active' => false]);
        Sanctum::actingAs($this->user('staff'));

        $this->getJson('/api/medical/doctor-options')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_suspended_login_is_not_offered(): void
    {
        $this->doctor(status: 'inactive');
        Sanctum::actingAs($this->user('staff'));

        $this->getJson('/api/medical/doctor-options')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_doctor_who_serves_only_purchase_is_absent_from_the_tpv_list(): void
    {
        $this->doctor(['modules' => ['purchase']]);
        Sanctum::actingAs($this->user('staff'));

        $this->getJson('/api/medical/doctor-options?module=tpv')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/medical/doctor-options?module=purchase')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_doctor_with_no_module_list_serves_both(): void
    {
        $this->doctor(['modules' => null]);
        Sanctum::actingAs($this->user('staff'));

        $this->getJson('/api/medical/doctor-options?module=tpv')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/medical/doctor-options?module=purchase')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_stranger_cannot_read_the_list(): void
    {
        $this->doctor();

        $this->getJson('/api/medical/doctor-options')->assertUnauthorized();
    }

    /* ── Picking one on a record ─────────────────────────────────────────── */

    public function test_picking_a_doctor_links_the_record_and_copies_the_licence(): void
    {
        $doc = $this->doctor(['license_no' => 'MH-12345', 'council' => 'MMC', 'clinic_name' => 'Ward Clinic']);
        $worker = $this->worker();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/tpv/workers/{$worker->id}/medical", [
            'fitness_status'  => 'Fit',
            'doctor_user_id'  => $doc->id,
            // Deliberately wrong: the directory must win over what was typed.
            'examiner_name'   => 'Dr Somebody Else',
            'exam_type'       => 'external',
        ])->assertOk();

        $this->assertDatabaseHas('tpv_worker_medicals', [
            'tpv_worker_id'     => $worker->id,
            'doctor_user_id'    => $doc->id,
            'doctor_license_no' => 'MH-12345',
            'doctor_council'    => 'MMC',
            'examiner_name'     => $doc->name,
            'clinic_name'       => 'Ward Clinic',
            // Choosing from the in-house directory IS the statement that this
            // was an internal examination.
            'exam_type'         => 'internal',
        ]);
    }

    public function test_typing_a_name_still_works_when_nobody_is_picked(): void
    {
        $worker = $this->worker();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/tpv/workers/{$worker->id}/medical", [
            'fitness_status' => 'Fit',
            'examiner_name'  => 'Dr Outside Person',
            'exam_type'      => 'external',
        ])->assertOk();

        $this->assertDatabaseHas('tpv_worker_medicals', [
            'tpv_worker_id'  => $worker->id,
            'examiner_name'  => 'Dr Outside Person',
            'exam_type'      => 'external',
            'doctor_user_id' => null,
        ]);
    }

    public function test_a_doctor_from_another_tenant_is_ignored_not_written(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $outsider = User::create([
            'tenant_id' => 2, 'name' => 'Other Doc', 'email' => 'other@t2.local',
            'password' => Hash::make(self::PASSWORD), 'role' => 'doctor', 'status' => 'active',
        ]);
        MedicalDoctorProfile::create([
            'tenant_id' => 2, 'user_id' => $outsider->id, 'license_no' => 'XX-1', 'is_active' => true,
        ]);

        $worker = $this->worker();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/tpv/workers/{$worker->id}/medical", [
            'fitness_status' => 'Fit',
            'examiner_name'  => 'Dr Typed',
            'doctor_user_id' => $outsider->id,
        ])->assertOk();

        // The forged id degrades to "nobody was picked" rather than writing a
        // dangling reference to another tenant's doctor.
        $this->assertDatabaseHas('tpv_worker_medicals', [
            'tpv_worker_id'  => $worker->id,
            'doctor_user_id' => null,
            'examiner_name'  => 'Dr Typed',
        ]);
    }

    public function test_a_deactivated_doctor_cannot_be_attached_even_by_id(): void
    {
        $doc = $this->doctor(['is_active' => false]);
        $worker = $this->worker();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/tpv/workers/{$worker->id}/medical", [
            'fitness_status' => 'Fit',
            'examiner_name'  => 'Dr Typed',
            'doctor_user_id' => $doc->id,
        ])->assertOk();

        $this->assertDatabaseHas('tpv_worker_medicals', [
            'tpv_worker_id'  => $worker->id,
            'doctor_user_id' => null,
        ]);
    }
}
