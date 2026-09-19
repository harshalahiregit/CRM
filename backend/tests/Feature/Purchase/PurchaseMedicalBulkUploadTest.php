<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Importing many fitness certificates at once — the second half of SIR-000015.
 *
 * "Bulk upload option required in - adding workforce, Medical test." The
 * workforce importer was already reachable; this one was not. The service, its
 * batch record and its per-row error list all existed and nothing in the UI ever
 * called them, so a vendor arriving with a stack of certificates was entered one
 * worker at a time.
 *
 * Rows are matched to workers by `worker_code`, which is the part worth pinning:
 * a silent mismatch there imports nothing and reports success.
 */
class PurchaseMedicalBulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(): PurchaseVendor
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
        $this->markOnboarded($vendor);

        return $vendor->fresh();
    }

    private function worker(PurchaseVendor $v, string $code, string $name = 'Ravi Kumar'): PurchaseWorker
    {
        return PurchaseWorker::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id,
            'full_name' => $name, 'worker_code' => $code,
            'gender' => 'Male', 'status' => 'Pending', 'current_step' => 1,
        ]);
        $this->markOnboarded($vendor);
    }

    private function sheet(string $body): UploadedFile
    {
        $header = 'worker_code,exam_date,valid_until,fitness_status,doctor_name,'
            .'doctor_license_no,clinic_name,blood_group,height_cm,weight_kg,'
            ."bp_systolic,bp_diastolic,restrictions,remarks\n";

        return UploadedFile::fake()->createWithContent('medical.csv', $header.$body);
    }

    /* ── the path the new button takes ───────────────────────────────── */

    public function test_a_sheet_of_certificates_is_imported(): void
    {
        $v = $this->vendor();
        $this->worker($v, 'PW-0001', 'Ravi Kumar');
        $this->worker($v, 'PW-0002', 'Sunita Rao');

        Sanctum::actingAs($this->admin());

        $res = $this->post('/api/purchase/medical/bulk', [
            'vendor_id' => $v->id,
            'file' => $this->sheet(
                "PW-0001,2026-09-01,2027-09-01,Fit,Dr A Mehta,MH-99213,City Clinic,B+,172,68,120,80,,Cleared\n"
                ."PW-0002,2026-09-01,2027-09-01,Fit,Dr A Mehta,MH-99213,City Clinic,O+,165,72,130,85,,Cleared\n"
            ),
        ])->assertCreated();

        $this->assertSame(2, (int) ($res->json('data.created_count') ?? 0),
            'both rows should have been imported');

        $this->assertDatabaseCount('purchase_worker_medicals', 2);
    }

    /**
     * A row naming a worker who does not exist must be REPORTED, not dropped.
     *
     * A batch that says "1 imported" and nothing else leaves nobody able to find
     * the one that did not.
     */
    public function test_an_unmatched_worker_code_is_named_not_silently_skipped(): void
    {
        $v = $this->vendor();
        $this->worker($v, 'PW-0001');

        Sanctum::actingAs($this->admin());

        $res = $this->post('/api/purchase/medical/bulk', [
            'vendor_id' => $v->id,
            'file' => $this->sheet(
                "PW-0001,2026-09-01,2027-09-01,Fit,Dr A,MH-1,Clinic,B+,172,68,120,80,,ok\n"
                ."PW-NOPE,2026-09-01,2027-09-01,Fit,Dr A,MH-1,Clinic,B+,172,68,120,80,,ok\n"
            ),
        ])->assertCreated();

        $this->assertSame(1, (int) ($res->json('data.created_count') ?? 0));
        $this->assertSame(1, (int) ($res->json('data.failed_count') ?? 0));

        $errors = $res->json('data.errors') ?? [];
        $this->assertNotEmpty($errors, 'the rejected row must be named');
        $this->assertSame('PW-NOPE', $errors[0]['worker_code'] ?? null);
    }

    /** The one column the importer cannot work without. */
    public function test_a_sheet_without_worker_code_is_refused_with_a_reason(): void
    {
        $this->vendor();
        Sanctum::actingAs($this->admin());

        $file = UploadedFile::fake()->createWithContent('bad.csv',
            "name,exam_date\nRavi Kumar,2026-09-01\n");

        $res = $this->post('/api/purchase/medical/bulk', ['file' => $file]);

        $this->assertGreaterThanOrEqual(400, $res->getStatusCode());
        $this->assertStringContainsString('worker_code', strtolower((string) $res->json('message')));
    }

    /** A bad fitness value must not become a silent default. */
    public function test_an_unknown_fitness_status_is_rejected_for_that_row(): void
    {
        $v = $this->vendor();
        $this->worker($v, 'PW-0001');

        Sanctum::actingAs($this->admin());

        $res = $this->post('/api/purchase/medical/bulk', [
            'vendor_id' => $v->id,
            'file' => $this->sheet("PW-0001,2026-09-01,2027-09-01,Banana,Dr A,MH-1,Clinic,B+,172,68,120,80,,ok\n"),
        ])->assertCreated();

        $this->assertSame(0, (int) ($res->json('data.created_count') ?? 0));
        $this->assertDatabaseCount('purchase_worker_medicals', 0);
    }

    /** Naming a vendor narrows the match, which is the whole point of the field. */
    public function test_the_vendor_filter_scopes_the_worker_code_lookup(): void
    {
        $mine = $this->vendor();
        $theirs = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Northgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'ng-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
        $this->markOnboarded($theirs);
        $this->worker($theirs, 'PW-0009', 'Someone Else');

        Sanctum::actingAs($this->admin());

        $res = $this->post('/api/purchase/medical/bulk', [
            'vendor_id' => $mine->id,
            'file' => $this->sheet("PW-0009,2026-09-01,2027-09-01,Fit,Dr A,MH-1,Clinic,B+,172,68,120,80,,ok\n"),
        ])->assertCreated();

        $this->assertSame(0, (int) ($res->json('data.created_count') ?? 0),
            'a code belonging to another vendor must not match when a vendor is named');
        $this->assertDatabaseCount('purchase_worker_medicals', 0);
    }
}
