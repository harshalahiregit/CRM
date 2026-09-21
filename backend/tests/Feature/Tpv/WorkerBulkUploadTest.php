<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bulk worker import — the reported faults.
 *
 * Two things went wrong in the field and both are covered here:
 *
 *  1. A vendor uploading from their own portal was told "Unauthorized. Required
 *     role: admin or staff" — the portal was posting to the ADMIN route.
 *  2. An admin picked a vendor, the import said it worked, and the workers were
 *     nowhere to be seen. The chosen vendor was fourth in a fallback chain, so
 *     the rows could land under a vendor nobody selected.
 */
class WorkerBulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    /* ── Fixtures ───────────────────────────────────────────────────────── */

    private function user(string $role, ?string $email = null): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $email ?: $role.'-'.Str::random(8).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name, ?string $email = null): Vendor
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => $email ?: strtolower($name).'-'.Str::random(4).'@t.local',
            'status' => 'Active',
        ]);
        $this->markOnboarded($vendor);

        return $vendor->fresh();
    }

    /** The sheet the sample template produces. */
    private function sheet(array $rows = null): UploadedFile
    {
        $header = 'Full Name,Gender,DOB,Mobile,Blood Group,Designation,Skill Category,Aadhaar Number';
        $rows ??= [
            'Suresh Patil,Male,15-05-90,9876543210,B+,Electrician,Skilled,123456789012',
            'Ramesh Kumar,Male,20-08-92,9876543211,O+,Mason,Semi Skilled,234567890123',
            'Priya Sharma,Female,10-02-95,9876543212,A+,Helper,Unskilled,345678901234',
        ];

        return UploadedFile::fake()->createWithContent(
            'workforce_bulk_upload_sample.csv',
            $header."\n".implode("\n", $rows)."\n",
        );
    }

    /* ── 1 · The admin's choice of vendor must win ──────────────────────── */

    public function test_an_admin_import_lands_on_the_vendor_the_admin_picked(): void
    {
        // The trap: an admin whose e-mail happens to match another vendor's.
        // That match used to outrank the vendor actually chosen in the form.
        $decoy  = $this->vendor('Decoy Contracting', 'admin@t.local');
        $chosen = $this->vendor('Chosen Contracting');
        $admin  = $this->user('admin', 'admin@t.local');

        Sanctum::actingAs($admin);

        $res = $this->post('/api/tpv/workers/upload', [
            'worker_file' => $this->sheet(),
            'vendor_id'   => $chosen->id,
        ])->assertOk();

        $this->assertSame(3, $res->json('inserted'));

        $this->assertSame(3, TpvWorker::where('vendor_id', $chosen->id)->count(),
            'the workers belong to the vendor the admin selected');
        $this->assertSame(0, TpvWorker::where('vendor_id', $decoy->id)->count(),
            'and never to a vendor matched by some other rule');
    }

    public function test_an_import_without_a_vendor_is_refused_rather_than_guessed(): void
    {
        // There is a vendor in the tenant, so the old code would have quietly
        // used it. Guessing is how an import disappears.
        $this->vendor('Some Other Vendor');
        Sanctum::actingAs($this->user('admin'));

        $this->post('/api/tpv/workers/upload', ['worker_file' => $this->sheet()])
            ->assertStatus(422);

        $this->assertSame(0, TpvWorker::count());
    }

    /* ── 2 · The imported workers are actually visible ──────────────────── */

    public function test_imported_workers_show_in_the_admin_list_and_the_vendor_portal(): void
    {
        $login  = $this->user('third_party_vendor');
        $vendor = $this->vendor('Acme', $login->email);
        $vendor->update(['user_id' => $login->id]);

        Sanctum::actingAs($this->user('admin'));
        $this->post('/api/tpv/workers/upload', [
            'worker_file' => $this->sheet(),
            'vendor_id'   => $vendor->id,
        ])->assertOk();

        // The admin roster, filtered to that vendor.
        $adminRows = $this->getJson('/api/tpv/workers?vendor_id='.$vendor->id)->assertOk()->json();
        $adminRows = $adminRows['data'] ?? $adminRows;
        $this->assertCount(3, $adminRows, 'the admin roster shows what was just imported');

        // And the vendor's own portal.
        Sanctum::actingAs($login);
        $portalRows = $this->getJson('/api/portal/workers')->assertOk()->json();
        $portalRows = $portalRows['data'] ?? $portalRows;
        $this->assertCount(3, $portalRows, 'and so does the vendor, on their own portal');
    }

    /* ── 3 · A vendor may import their own workers ──────────────────────── */

    public function test_a_vendor_can_bulk_upload_from_their_own_portal(): void
    {
        $login  = $this->user('third_party_vendor');
        $vendor = $this->vendor('Acme', $login->email);
        $vendor->update(['user_id' => $login->id]);

        Sanctum::actingAs($login);

        $res = $this->post('/api/portal/workers/upload', ['worker_file' => $this->sheet()])
            ->assertOk();

        $this->assertSame(3, $res->json('inserted'));
        $this->assertSame(3, TpvWorker::where('vendor_id', $vendor->id)->count());
    }

    public function test_a_vendor_cannot_import_against_another_vendor(): void
    {
        $login  = $this->user('third_party_vendor');
        $mine   = $this->vendor('Mine', $login->email);
        $mine->update(['user_id' => $login->id]);
        $theirs = $this->vendor('Theirs');

        Sanctum::actingAs($login);

        // The vendor id is taken from the token; a payload one is ignored.
        $this->post('/api/portal/workers/upload', [
            'worker_file' => $this->sheet(),
            'vendor_id'   => $theirs->id,
        ])->assertOk();

        $this->assertSame(3, TpvWorker::where('vendor_id', $mine->id)->count());
        $this->assertSame(0, TpvWorker::where('vendor_id', $theirs->id)->count());
    }

    /* ── 3b · One vendor never sees another's workers ───────────────────── */

    public function test_a_vendor_never_sees_the_first_vendors_workers(): void
    {
        // The first vendor created in the tenant — the one the old upload path
        // dumped unattributed imports onto.
        $first = $this->vendor('First Vendor Ever');
        TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $first->id, 'name' => 'Somebody Elses Worker',
            'worker_code' => 'W-00001', 'current_step' => 1, 'status' => 'Draft',
        ]);

        $login = $this->user('third_party_vendor');
        $mine  = $this->vendor('Acme', $login->email);
        $mine->update(['user_id' => $login->id]);

        Sanctum::actingAs($login);
        $rows = $this->getJson('/api/portal/workers')->assertOk()->json();
        $rows = $rows['data'] ?? $rows;

        // The roster used to add "or vendor_id = 1" to every vendor filter, so
        // the first vendor's workforce appeared inside everybody else's portal.
        $this->assertCount(0, $rows, 'a vendor sees only their own workers');
    }

    /* ── 4 · Honest reporting ───────────────────────────────────────────── */

    public function test_re_importing_the_same_sheet_reports_duplicates_and_says_so_plainly(): void
    {
        $vendor = $this->vendor('Acme');
        Sanctum::actingAs($this->user('admin'));

        $this->post('/api/tpv/workers/upload', ['worker_file' => $this->sheet(), 'vendor_id' => $vendor->id])->assertOk();

        $again = $this->post('/api/tpv/workers/upload', ['worker_file' => $this->sheet(), 'vendor_id' => $vendor->id])
            ->assertOk();

        $this->assertSame(0, $again->json('inserted'));
        $this->assertSame(3, $again->json('skipped'));
        // "0 worker(s) imported successfully" is what made this look like a
        // success. Nothing was imported, and the message has to say that first.
        $this->assertStringNotContainsString('imported successfully', $again->json('message'));
        $this->assertStringContainsString('Nothing was imported', $again->json('message'));
        // And it names WHO was skipped, so the reader can tell duplicates from a
        // silent failure.
        $this->assertStringContainsString('Suresh Patil', implode(' ', $again->json('duplicates') ?? []));

        $this->assertSame(3, TpvWorker::where('vendor_id', $vendor->id)->count());
    }

    public function test_an_excel_mangled_aadhaar_is_reported_not_silently_skipped(): void
    {
        $vendor = $this->vendor('Acme');
        Sanctum::actingAs($this->user('admin'));

        // Excel turns a 12-digit Aadhaar into scientific notation the moment the
        // sheet is opened and saved. That is what a real upload looks like.
        $res = $this->post('/api/tpv/workers/upload', [
            'worker_file' => $this->sheet([
                'Suresh Patil,Male,15-05-90,9876543210,B+,Electrician,Skilled,1.23E+11',
            ]),
            'vendor_id' => $vendor->id,
        ])->assertOk();

        $this->assertSame(0, $res->json('inserted'));
        $errors = implode(' ', $res->json('errors') ?? []);
        $this->assertStringContainsString('Row 2', $errors);
        // The message has to name the cause, or the reader retries the same file.
        $this->assertStringContainsString('scientific notation', $errors);
    }
}
