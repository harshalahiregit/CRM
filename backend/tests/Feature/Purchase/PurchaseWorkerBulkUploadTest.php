<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase can register workers from a sheet — the feature TPV has always had.
 *
 * A Purchase vendor arriving with forty people had to enter them one at a time,
 * because bulk import did not exist on that engine at all: no route, no service,
 * no button. TPV has had it for both its admin and its portal throughout.
 *
 * The reading is shared with TPV (see WorkerImport) so the same template works on
 * both engines; the writing is Purchase's own, against purchase_workers. What is
 * proved here is the behaviour that makes an import trustworthy:
 *
 *   · it lands on the vendor the operator PICKED, never a guessed one;
 *   · a vendor importing from its own portal cannot aim at anybody else;
 *   · duplicates and unreadable rows are NAMED, not merely counted;
 *   · when nothing lands, it says so instead of reporting success.
 *
 * @see \Tests\Feature\Tpv\WorkerBulkUploadTest
 */
class PurchaseWorkerBulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    private PurchaseVendor $rival;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = $this->makeVendor('Southgate Industrial');
        $this->rival = $this->makeVendor('Rival Ltd');

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya Nair', 'role' => 'admin',
            'email' => 'a-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function makeVendor(string $name): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => Str::random(5).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    /** The template's column order, shared with TPV. */
    private function sheet(array $rows): UploadedFile
    {
        $csv = "Name,Gender,DOB,Mobile,Blood,Designation,Skill,ID Number,Photo\n";
        foreach ($rows as $r) {
            $csv .= implode(',', $r)."\n";
        }

        return UploadedFile::fake()->createWithContent('workers.csv', $csv);
    }

    private function twoWorkers(): UploadedFile
    {
        return $this->sheet([
            ['Rita Bose', 'Female', '1990-04-12', '9820011223', 'O+', 'Fitter', 'Skilled', '123456789012', ''],
            ['Anil Kumar', 'Male', '12/06/1988', '9820011224', 'B+', 'Helper', 'Unskilled', '123456789013', ''],
        ]);
    }

    /* ── the admin import ────────────────────────────────────────────────── */

    public function test_an_admin_import_lands_on_the_vendor_the_admin_picked(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->post('/api/purchase/workforce/workers/upload', [
            'worker_file' => $this->twoWorkers(),
            'vendor_id' => $this->vendor->id,
        ])->assertOk();

        $this->assertSame(2, $res->json('inserted'));
        $this->assertSame(2, PurchaseWorker::where('purchase_vendor_id', $this->vendor->id)->count());
        $this->assertSame(0, PurchaseWorker::where('purchase_vendor_id', $this->rival->id)->count(),
            'an import must never land on a vendor nobody chose');
    }

    /** Every imported row is a real worker, not a name in a list. */
    public function test_the_imported_rows_become_usable_workers(): void
    {
        Sanctum::actingAs($this->admin);
        $this->post('/api/purchase/workforce/workers/upload', [
            'worker_file' => $this->twoWorkers(), 'vendor_id' => $this->vendor->id,
        ])->assertOk();

        $rita = PurchaseWorker::where('full_name', 'Rita Bose')->sole();

        $this->assertSame('Female', $rita->gender);
        $this->assertSame('1990-04-12', $rita->dob->format('Y-m-d'));
        $this->assertSame('9820011223', $rita->phone);
        $this->assertSame('Fitter', $rita->designation);
        $this->assertSame('123456789012', $rita->id_proof_number);
        $this->assertSame('Pending', $rita->status, 'an imported worker still has to go through the 5 steps');
        $this->assertSame(1, (int) $rita->current_step);
        $this->assertNotEmpty($rita->worker_code, 'without a code the badge step has nothing to print');

        // dd/mm/yyyy is what people actually type, and it must not be read as US order.
        $this->assertSame('1988-06-12', PurchaseWorker::where('full_name', 'Anil Kumar')->sole()->dob->format('Y-m-d'));
    }

    /**
     * A vendor nobody chose is an error, not a guess.
     *
     * TPV once fell back through a chain of maybes and could land an import on
     * the first vendor in the tenant.
     */
    public function test_an_import_without_a_vendor_is_refused_rather_than_guessed(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/purchase/workforce/workers/upload', [
            'worker_file' => $this->twoWorkers(),
        ])->assertStatus(422);

        $this->assertSame(0, PurchaseWorker::count());
    }

    /* ── the vendor's own import ─────────────────────────────────────────── */

    public function test_a_vendor_can_bulk_upload_from_its_own_portal(): void
    {
        Sanctum::actingAs($this->vendor);

        $res = $this->post('/api/portal/purchase/workers/upload', [
            'worker_file' => $this->twoWorkers(),
        ])->assertOk();

        $this->assertSame(2, $res->json('inserted'));
        $this->assertSame(2, PurchaseWorker::where('purchase_vendor_id', $this->vendor->id)->count());
    }

    /**
     * The portal takes no vendor_id, so one cannot be smuggled in.
     */
    public function test_a_vendor_cannot_import_against_another_vendor(): void
    {
        Sanctum::actingAs($this->vendor);

        $this->post('/api/portal/purchase/workers/upload', [
            'worker_file' => $this->twoWorkers(),
            'vendor_id' => $this->rival->id,          // ignored
        ])->assertOk();

        $this->assertSame(2, PurchaseWorker::where('purchase_vendor_id', $this->vendor->id)->count());
        $this->assertSame(0, PurchaseWorker::where('purchase_vendor_id', $this->rival->id)->count());
    }

    /* ── saying what happened ────────────────────────────────────────────── */

    /**
     * "2 skipped" is indistinguishable from an import that quietly broke.
     */
    public function test_re_importing_the_same_sheet_names_the_duplicates(): void
    {
        Sanctum::actingAs($this->vendor);
        $this->post('/api/portal/purchase/workers/upload', ['worker_file' => $this->twoWorkers()])->assertOk();

        $res = $this->post('/api/portal/purchase/workers/upload', ['worker_file' => $this->twoWorkers()])->assertOk();

        $this->assertSame(0, $res->json('inserted'));
        $this->assertSame(2, $res->json('skipped'));
        $this->assertSame('warning', $res->json('status'), 'nothing landed, so this is not a success');
        $this->assertStringContainsString('Nothing was imported', $res->json('message'));

        $duplicates = implode(' ', $res->json('duplicates'));
        $this->assertStringContainsString('Rita Bose', $duplicates, 'name them, do not just count them');
        $this->assertStringContainsString('already registered', $duplicates);

        $this->assertSame(2, PurchaseWorker::count(), 'and nothing was written twice');
    }

    /**
     * Excel rewrites a 12-digit Aadhaar as 1.23E+11 the moment the sheet is
     * saved. "Invalid" sends the reader hunting for a typo that is not there.
     */
    public function test_an_excel_mangled_id_number_is_reported_with_its_cause(): void
    {
        Sanctum::actingAs($this->vendor);

        $res = $this->post('/api/portal/purchase/workers/upload', [
            'worker_file' => $this->sheet([
                ['Rita Bose', 'Female', '1990-04-12', '9820011223', 'O+', 'Fitter', 'Skilled', '1.23457E+11', ''],
            ]),
        ])->assertOk();

        $this->assertSame(0, $res->json('inserted'));
        $errors = implode(' ', $res->json('errors'));
        $this->assertStringContainsString('scientific notation', $errors);
        $this->assertStringContainsString('Format the column as Text', $errors,
            'the message has to say how to fix it, not just that it is wrong');
    }

    /** An empty sheet says so rather than reporting a successful import of none. */
    public function test_an_empty_sheet_is_not_reported_as_a_success(): void
    {
        Sanctum::actingAs($this->vendor);

        $res = $this->post('/api/portal/purchase/workers/upload', [
            'worker_file' => $this->sheet([]),
        ])->assertOk();

        $this->assertSame('warning', $res->json('status'));
        $this->assertStringContainsString('no usable rows', $res->json('message'));
    }

    /** Two people with the same name are two people. */
    public function test_namesakes_with_different_ids_both_import(): void
    {
        Sanctum::actingAs($this->vendor);

        $res = $this->post('/api/portal/purchase/workers/upload', [
            'worker_file' => $this->sheet([
                ['Anil Kumar', 'Male', '1988-06-12', '9820011224', 'B+', 'Helper', 'Unskilled', '123456789013', ''],
                ['Anil Kumar', 'Male', '1991-02-03', '9820011225', 'A+', 'Helper', 'Unskilled', '123456789014', ''],
            ]),
        ])->assertOk();

        $this->assertSame(2, $res->json('inserted'), 'a shared name is not a duplicate');
    }
}
