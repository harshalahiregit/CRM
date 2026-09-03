<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseWorkforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TPV parity for the Purchase medical: the examiner's signature, the legal
 * capture that ties it to a place and a device, and the examination recorded as
 * columns rather than folded into `remarks` as prose.
 *
 * purchase_worker_medicals has carried these columns for a while; nothing wrote
 * them, because the wizard had no signature pad and posted five fields plus a
 * paragraph. The endpoint now takes the base64 capture and decodes it.
 */
class PurchaseMedicalSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const PNG = 'data:image/png;base64,iVBORw0KGgo=';

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function worker(): PurchaseWorker
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'acme-'.Str::random(3).'@test.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        return app(PurchaseWorkforceService::class)->create($vendor, [
            'full_name' => 'Ravi', 'dob' => '1990-01-01', 'designation' => 'Fitter', 'phone' => '9990000000',
        ]);
    }

    private function saveMedical(PurchaseWorker $worker, array $payload)
    {
        return $this->postJson("/api/purchase/workforce/workers/{$worker->id}/medical", $payload);
    }

    public function test_the_signature_and_scene_photo_are_decoded_and_stored(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin());
        $worker = $this->worker();

        $this->saveMedical($worker, [
            'fitness_status' => 'Fit',
            'exam_type'      => 'internal',
            'examiner_name'  => 'Dr Meera',
            'signature_data' => self::PNG,
            'capture_photo'  => self::PNG,
            'geo_location'   => '12.340000,56.780000',
        ])->assertOk();

        $medical = $worker->fresh('medicals')->medicals->first();

        $this->assertNotNull($medical->signature_path, 'the signature should be stored, not dropped');
        $this->assertNotNull($medical->capture_photo_path);
        Storage::disk('public')->assertExists($medical->signature_path);
        Storage::disk('public')->assertExists($medical->capture_photo_path);

        $this->assertSame('12.340000,56.780000', $medical->geo_location);
        $this->assertNotEmpty($medical->system_ip, 'the server stamps the caller IP');
    }

    public function test_an_external_exam_keeps_its_signature_too(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin());
        $worker = $this->worker();

        $this->saveMedical($worker, [
            'fitness_status' => 'Fit_With_Restrictions',
            'exam_type'      => 'external',
            'examiner_name'  => 'Dr外',
            'signature_data' => self::PNG,
        ])->assertOk();

        $medical = $worker->fresh('medicals')->medicals->first();

        $this->assertSame('external', $medical->exam_type);
        $this->assertNotNull($medical->signature_path);
        Storage::disk('public')->assertExists($medical->signature_path);
    }

    public function test_a_client_cannot_name_the_stored_path_itself(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin());
        $worker = $this->worker();

        // The *_path columns are not validated keys, so they never reach the
        // service — the record cannot be pointed at a file the caller chose.
        $this->saveMedical($worker, [
            'fitness_status'     => 'Fit',
            'signature_path'     => 'someone/elses/signature.png',
            'capture_photo_path' => 'someone/elses/photo.png',
        ])->assertOk();

        $medical = $worker->fresh('medicals')->medicals->first();
        $this->assertNull($medical->signature_path);
        $this->assertNull($medical->capture_photo_path);
    }

    public function test_the_examination_is_recorded_as_columns_not_only_prose(): void
    {
        Sanctum::actingAs($this->admin());
        $worker = $this->worker();

        $this->saveMedical($worker, [
            'fitness_status'      => 'Fit',
            'exam_type'           => 'internal',
            'clinic_name'         => 'Site Clinic',
            'height_cm'           => 172,
            'weight_kg'           => 68,
            'bp_systolic'         => 120,
            'bp_diastolic'        => 80,
            'vision'              => '6/6',
            'screening_responses' => ['mh_q1' => 2, 'mh_q2' => 1],
            'screening_score'     => 6,
        ])->assertOk();

        $medical = $worker->fresh('medicals')->medicals->first();

        $this->assertSame('Site Clinic', $medical->clinic_name);
        $this->assertEqualsWithDelta(172, $medical->height_cm, 0.01);
        $this->assertEqualsWithDelta(68, $medical->weight_kg, 0.01);
        $this->assertSame(120, $medical->bp_systolic);
        $this->assertSame('6/6', $medical->vision);
        $this->assertSame(['mh_q1' => 2, 'mh_q2' => 1], $medical->screening_responses);
        // The band is ours to derive — 6 sits in the Moderate range.
        $this->assertSame('Moderate', $medical->screening_band);
    }

    public function test_the_screening_band_is_derived_not_taken_from_the_client(): void
    {
        Sanctum::actingAs($this->admin());
        $worker = $this->worker();

        $this->saveMedical($worker, [
            'fitness_status'  => 'Fit',
            'screening_score' => 11,
            'screening_band'  => 'Low',   // a client claiming the wrong band
        ])->assertOk();

        $this->assertSame('High', $worker->fresh('medicals')->medicals->first()->screening_band);
    }

    public function test_a_medical_without_any_capture_still_saves(): void
    {
        Sanctum::actingAs($this->admin());
        $worker = $this->worker();

        $this->saveMedical($worker, ['fitness_status' => 'Fit'])->assertOk();

        $medical = $worker->fresh('medicals')->medicals->first();
        $this->assertNull($medical->signature_path);
        $this->assertNull($medical->screening_band);
    }
}
