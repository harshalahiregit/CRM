<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Registering a worker: choosing the vendor, saving, and the bulk import.
 *
 * Reported from the running system: the vendor cannot be selected on the worker
 * form, and the bulk upload misbehaves.
 *
 * The vendor dropdown is fed by GET /purchase/vendors and read as
 * `res?.data ?? res`, so this asserts the exact shape that expression needs —
 * a payload that is an object with no `data` key, or a paginator the client
 * does not unwrap, leaves the list empty with no error anywhere.
 */
class PurchaseWorkerRegistrationTest extends TestCase
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

    private function vendor(string $name = 'Southgate', string $status = 'Active'): PurchaseVendor
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($name).'-'.Str::random(4).'@t.local',
            'status' => $status, 'portal_status' => 'active',
        ]);
        $this->markOnboarded($vendor);

        return $vendor->fresh();
    }

    /* ── the dropdown ───────────────────────────────────────────────── */

    public function test_the_vendor_dropdown_is_populated(): void
    {
        $this->vendor('Southgate');
        $this->vendor('Northgate');

        Sanctum::actingAs($this->admin());
        $res = $this->getJson('/api/purchase/vendors?per_page=200')->assertOk();

        $rows = $res->json();
        // The client does `res?.data ?? res` then Array.isArray(). If the payload
        // is a paginator object the client hands setVendors() a non-array and the
        // dropdown renders with only its placeholder — no error, no clue.
        $list = $rows['data'] ?? $rows;

        $this->assertIsArray($list, 'the vendor list must arrive as an array the picker can map over');
        $this->assertCount(2, $list);

        // The option label is `${v.company_name} · ${v.status_label || v.status}`,
        // so a missing company_name renders a row of separators.
        $this->assertArrayHasKey('company_name', $list[0]);
        $this->assertArrayHasKey('id', $list[0]);
        $this->assertNotEmpty($list[0]['company_name']);
    }

    public function test_every_vendor_is_offered_not_only_the_active_ones(): void
    {
        $this->vendor('Active Co', 'Active');
        $this->vendor('Draft Co', 'Draft');
        $this->vendor('Pending Co', 'Pending_Approval');

        Sanctum::actingAs($this->admin());
        $rows = $this->getJson('/api/purchase/vendors?per_page=200')->assertOk()->json();
        $names = collect($rows['data'] ?? $rows)->pluck('company_name')->all();

        // A worker is often registered while their employer is still being
        // onboarded. Hiding non-active vendors would make the dropdown look
        // empty for exactly the vendors somebody is working on.
        $this->assertContains('Draft Co', $names);
        $this->assertContains('Pending Co', $names);
    }

    /* ── saving the worker ──────────────────────────────────────────── */

    public function test_a_worker_is_created_against_the_chosen_vendor(): void
    {
        $vendor = $this->vendor();

        Sanctum::actingAs($this->admin());
        $res = $this->postJson('/api/purchase/workforce/workers', [
            'purchase_vendor_id' => $vendor->id,
            'full_name' => 'Ravi Kumar',
            'dob' => now()->subYears(30)->toDateString(),
            'gender' => 'Male',
            'trade' => 'Fitter',
        ]);

        if ($res->getStatusCode() >= 400) {
            $this->fail('worker create refused: '.$res->getStatusCode().' — '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }

        $this->assertDatabaseHas('purchase_workers', [
            'purchase_vendor_id' => $vendor->id,
            'full_name' => 'Ravi Kumar',
        ]);
    }

    public function test_a_worker_cannot_be_created_without_a_vendor(): void
    {
        Sanctum::actingAs($this->admin());

        // A worker with no employer is a person nobody is accountable for.
        $this->postJson('/api/purchase/workforce/workers', [
            'full_name' => 'Ravi Kumar',
            'dob' => now()->subYears(30)->toDateString(),
            'gender' => 'Male',
        ])->assertStatus(422);
    }

    /* ── the bulk import ────────────────────────────────────────────── */

    public function test_the_bulk_upload_accepts_a_csv_and_creates_the_workers(): void
    {
        $vendor = $this->vendor();

        Sanctum::actingAs($this->admin());

        $csv = "full_name,dob,gender,trade\n"
            ."Ravi Kumar,1994-03-02,Male,Fitter\n"
            ."Sunita Rao,1990-07-19,Female,Welder\n";

        // The real endpoint and the real field names: `worker_file` and
        // `vendor_id`, posted to /workers/upload. `/workers/bulk` does not exist
        // and is swallowed by the {worker} wildcard, which answers 405 — a
        // confusing way to learn you have the wrong path.
        $res = $this->post('/api/purchase/workforce/workers/upload', [
            'vendor_id' => $vendor->id,
            'worker_file' => UploadedFile::fake()->createWithContent('workers.csv', $csv),
        ], ['Accept' => 'application/json']);

        if ($res->getStatusCode() >= 400) {
            $this->fail('bulk upload refused: '.$res->getStatusCode().' — '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }

        $this->assertDatabaseHas('purchase_workers', ['full_name' => 'Ravi Kumar']);
        $this->assertDatabaseHas('purchase_workers', ['full_name' => 'Sunita Rao']);
    }

    public function test_the_import_names_the_vendor_when_it_is_missing(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->post('/api/purchase/workforce/workers/upload', [
            'worker_file' => UploadedFile::fake()->createWithContent(
                'workers.csv', "full_name,dob,gender
Ravi,1994-03-02,Male
"),
        ], ['Accept' => 'application/json']);

        $res->assertStatus(422);

        // "The vendor id field is required" tells somebody nothing about which
        // control to touch. This message names the thing to do.
        $this->assertStringContainsString('Choose the vendor',
            (string) json_encode($res->json('errors')));
    }

    // The CSV template is generated in the browser (see BulkUploadModal), so
    // there is deliberately no endpoint for it -- one fewer round trip, and the
    // sample always matches the columns the client just built.
}
