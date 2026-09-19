<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Purchase half of the same walk, through the real endpoints:
 *
 *   admin registers a Purchase vendor
 *     → the vendor signs in to its own portal with its own token
 *       → a worker is registered against it
 *         → the medical is recorded and the worker reads back with it
 *           → the vendor sees, from the portal, only its own records
 *
 * The two engines share no code and no tables, so proving TPV works proves
 * nothing here. This exists to walk Purchase's own chain rather than assume
 * parity.
 *
 * @see \Tests\Feature\Tpv\VendorLifecycleEndToEndTest for the TPV chain.
 */
class PurchaseVendorLifecycleEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(5).'@t.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    /** POST, and say WHY when the server refuses — see the TPV walk. */
    private function send(string $uri, array $payload = [], int $expect = 200)
    {
        $res = $this->postJson($uri, $payload);

        if ($res->getStatusCode() !== $expect) {
            $body = $res->json();
            $this->fail(sprintf(
                "POST %s expected %d, got %d\n%s",
                $uri, $expect, $res->getStatusCode(),
                json_encode($body['errors'] ?? $body['message'] ?? $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ));
        }

        return $res;
    }

    private function registerVendor(string $name = 'Southgate Supplies'): PurchaseVendor
    {
        $this->asAdmin();

        $id = $this->send('/api/purchase/vendors', [
            'company_name' => $name,
            // Purchase asks for more than TPV on purpose: a supplier is
            // categorised and priced in a currency from the moment it exists.
            'vendor_type'  => 'standard',
            'category'     => 'Consumables',
            'currency'     => 'INR',
            'email'        => strtolower(Str::slug($name)).'-'.Str::random(4).'@supplier.test',
            'phone'        => '9990002222',
        ], 201)->json('id');

        $vendor = PurchaseVendor::find($id);
        $this->assertNotNull($vendor, 'the Purchase vendor should exist after registration');
        $this->assertNotEmpty($vendor->purchase_vendor_code, 'a vendor without its code cannot be referenced');

        /*
         * Approve it, because the chain below starts by registering a workforce.
         *
         * A newly registered vendor is Draft, and a Draft vendor no longer
         * accepts workers — see PurchaseWorkforceService, added for SIR-000014,
         * which asked for exactly that. This step was missing here: the test
         * jumped from "vendor created" to "add its workers" and skipped the
         * approval that happens in between in the real product, so it was not
         * quite the end-to-end run it claims to be.
         */
        $this->asAdmin();
        $this->postJson("/api/purchase/vendors/{$vendor->id}/approve")->assertSuccessful();

        $vendor = $vendor->fresh();
        $this->assertSame('Active', $vendor->status, 'approval must leave the vendor engageable');

        return $vendor;
    }

    public function test_the_purchase_vendor_chain_runs_end_to_end(): void
    {
        /* 1 — the vendor */
        $vendor = $this->registerVendor();

        /* 2 — a worker against that vendor */
        $this->asAdmin();
        $workerId = $this->send('/api/purchase/workforce/workers', [
            'purchase_vendor_id' => $vendor->id,
            'full_name'          => 'Imran Shaikh',
            'dob'                => '1991-06-11',
            'phone'              => '9995551111',
            'designation'        => 'Fitter',
        ], 201)->json('id');

        $worker = PurchaseWorker::findOrFail($workerId);
        $this->assertSame($vendor->id, (int) $worker->purchase_vendor_id);
        $this->assertNotEmpty($worker->worker_code, 'a worker without a code cannot be badged');

        /* 3 — the medical, with the signature the portal work added */
        $this->asAdmin();
        $this->send("/api/purchase/workforce/workers/{$worker->id}/medical", [
            'fitness_status' => 'Fit',
            'exam_type'      => 'internal',
            'examiner_name'  => 'Dr Meera',
            'height_cm'      => 175,
            'weight_kg'      => 72,
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
        ]);

        $worker->refresh()->load('latestMedical');
        $this->assertNotNull($worker->latestMedical, 'the medical must attach to the worker');
        $this->assertSame('Fit', $worker->latestMedical->fitness_status);
        $this->assertNotNull($worker->latestMedical->signature_path,
            'the signature must be stored, not dropped');

        /* 4 — the worker reads back through the endpoint the screen uses */
        $this->asAdmin();
        $shown = $this->getJson("/api/purchase/workforce/workers/{$worker->id}")->assertOk()->json();
        $body  = $shown['worker'] ?? $shown;
        $this->assertSame('Imran Shaikh', $body['full_name'] ?? null);
    }

    /**
     * The vendor's own view. A Purchase vendor authenticates AS ITSELF — there is
     * no User behind it — so this also proves the token resolves the right vendor
     * with no id anywhere in the URL.
     */
    public function test_a_purchase_vendor_sees_only_its_own_records_from_the_portal(): void
    {
        $mine  = $this->registerVendor('Southgate Supplies');
        $rival = $this->registerVendor('Rival Traders');

        $this->asAdmin();
        foreach ([[$mine, 'My Worker'], [$rival, 'Their Worker']] as [$vendor, $name]) {
            $this->send('/api/purchase/workforce/workers', [
                'purchase_vendor_id' => $vendor->id,
                'full_name'          => $name,
                'dob'                => '1990-01-01',
                'phone'              => '99955'.random_int(10000, 99999),
            ], 201);
        }

        Sanctum::actingAs($mine);

        $me = $this->getJson('/api/portal/purchase/me')->assertOk()->json();
        $this->assertSame($mine->id, $me['vendor']['id'] ?? $me['id'] ?? null,
            'the portal must resolve the vendor from the token alone');

        // The roster the vendor reads must contain their worker and nobody else's.
        $roster = $this->getJson('/api/portal/purchase/workers')->assertOk()->json();
        $names  = collect($roster['workers'] ?? $roster['data'] ?? $roster)->pluck('full_name');

        $this->assertContains('My Worker', $names->all());
        $this->assertNotContains('Their Worker', $names->all(),
            'a vendor must never see another vendor\'s workforce');
    }
}
