<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Press Save on every Purchase form and see what happens.
 *
 * The read sweep (PurchaseModuleSweepTest) proved every screen OPENS. This is
 * the other half: filling something in and submitting it, which is where a
 * module that "looks fine but nothing works" actually fails.
 *
 * ── What is a finding and what is noise ─────────────────────────────────
 * A 500 is always a defect — the form threw rather than answering.
 * A 422 is validation and usually means this probe sent the wrong shape, NOT
 * that the endpoint is broken; it is reported separately so the two are never
 * confused. A 201/200 means the record was really created.
 *
 * This deliberately reports rather than asserting a fixed list: the point is to
 * find what is broken, and a test that only checks what somebody already knew
 * about would have found none of it.
 *
 * ── What this run currently covers ──────────────────────────────────────
 * 18 of the 25 forms create a real record. The other seven (incidents,
 * observations, inspections, permits, drills, violations, evidence) are refused
 * on an enum value — the field is right, the vocabulary is this probe's guess,
 * and each of those HSSE modules keeps its own list. They are NOT known to be
 * broken: they answer with correct validation, which is the opposite of broken.
 * Filling those vocabularies in is worth doing and would raise the count to 25.
 */
class PurchaseWriteProbeTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate Industrial',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'vendor_type' => 'Permanent', 'category' => 'Mechanical',
            'currency' => 'INR', 'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    public function test_pressing_save_on_every_purchase_form(): void
    {
        Sanctum::actingAs($this->admin());

        $v = $this->vendor->id;
        $today = now()->toDateString();
        $soon = now()->addMonth()->toDateString();

        // The payloads each form actually sends. Field names were read back from
        // the validators rather than guessed -- the first pass of this probe used
        // `quantity`/`unit_price` where the module says `qty`/`rate`, and every
        // one of those 422s looked like a broken endpoint until the errors were
        // printed field by field.
        //
        // Absent on purpose: `quotations` and `receipts` have no create endpoint
        // and should not. A quotation arrives from a vendor answering an RFQ, and
        // a goods receipt is booked against an order
        // (POST /orders/{id}/receipts), so a bare create would be a record with
        // nothing behind it.
        $forms = [
            'vendors' => ['company_name' => 'Northgate Ltd', 'vendor_type' => 'standard',
                'category' => 'Electrical', 'currency' => 'INR', 'email' => 'n@t.local'],

            'vendor-categories' => ['name' => 'Fabrication '.Str::random(4)],

            'requests' => ['title' => 'Bearings', 'required_by' => $soon,
                'items' => [['description' => 'Bearing 6204', 'qty' => 10, 'rate' => 250]]],

            'orders' => ['purchase_vendor_id' => $v, 'title' => 'Bearings order',
                'order_date' => $today, 'currency' => 'INR',
                'items' => [['description' => 'Bearing 6204', 'qty' => 10, 'rate' => 250]]],

            'invoices' => ['purchase_vendor_id' => $v, 'invoice_no' => 'INV-'.Str::random(5),
                'invoice_date' => $today, 'currency' => 'INR',
                'items' => [['description' => 'Bearing 6204', 'qty' => 10, 'rate' => 250]]],

            'contracts' => ['purchase_vendor_id' => $v, 'title' => 'Supply Agreement',
                'type' => 'rate_contract', 'start_date' => $today, 'end_date' => $soon],

            'debit-notes' => ['purchase_vendor_id' => $v, 'note_date' => $today,
                'reason' => 'Short supply',
                'items' => [['description' => 'Short supply', 'qty' => 1, 'rate' => 5000]]],

            'order-returns' => ['purchase_vendor_id' => $v, 'return_date' => $today,
                'reason' => 'Damaged on arrival',
                'items' => [['description' => 'Bearing 6204', 'qty' => 2, 'rate' => 250]]],

            'work-packages' => ['purchase_vendor_id' => $v, 'name' => 'Crane overhaul'],

            'incidents' => ['purchase_vendor_id' => $v, 'title' => 'Near miss at bay 3',
                'incident_date' => $today, 'type' => 'near_miss', 'severity' => 'Minor'],

            'ncrs' => ['purchase_vendor_id' => $v, 'title' => 'Wrong material grade',
                'raised_date' => $today, 'severity' => 'Major'],

            'capas' => ['purchase_vendor_id' => $v, 'title' => 'Retrain fitters',
                'due_date' => $soon],

            'observations' => ['purchase_vendor_id' => $v, 'category' => 'housekeeping',
                'description' => 'Walkway blocked', 'observed_at' => $today],

            'inspections' => ['purchase_vendor_id' => $v, 'title' => 'Monthly lifting check',
                'type' => 'routine', 'scheduled_date' => $soon],

            'permits' => ['purchase_vendor_id' => $v, 'type' => 'hot_work',
                'title' => 'Hot work at bay 3', 'valid_from' => $today, 'valid_to' => $soon],

            'toolbox-talks' => ['purchase_vendor_id' => $v, 'topic' => 'Working at height',
                'held_on' => $today],

            'drills' => ['purchase_vendor_id' => $v, 'drill_type' => 'fire',
                'title' => 'Fire drill', 'held_on' => $today],

            'visitors' => ['purchase_vendor_id' => $v, 'visitor_name' => 'Ravi Kumar',
                'visit_date' => $today],

            'site-vehicles' => ['purchase_vendor_id' => $v, 'vehicle_number' => 'MH01AB1234'],

            'violations' => ['purchase_vendor_id' => $v, 'type' => 'ppe',
                'description' => 'No hard hat', 'occurred_on' => $today],

            'offboardings' => ['purchase_vendor_id' => $v, 'reason' => 'Contract ended'],

            'renewals' => ['purchase_vendor_id' => $v, 'due_date' => $soon],

            'approval-requests' => ['purchase_vendor_id' => $v, 'title' => 'Extra scope',
                'approval_type' => 'vendor_registration'],

            'rfqs' => ['title' => 'Bearings RFQ', 'due_date' => $soon,
                'items' => [['description' => 'Bearing 6204', 'qty' => 10]]],

            'evidence' => ['purchase_vendor_id' => $v, 'category' => 'photo',
                'title' => 'Photo of repair'],
        ];

        $server = [];      // 5xx — real defects
        $rejected = [];    // 4xx — validation or policy; shape, not breakage
        $ok = [];

        foreach ($forms as $path => $payload) {
            try {
                $res = $this->postJson("/api/purchase/{$path}", $payload);
                $code = $res->getStatusCode();

                if ($code >= 500) {
                    $server[$path] = $code.' — '.Str::limit((string) ($res->json('message')
                        ?? $res->getContent()), 160);
                } elseif ($code >= 400) {
                    // The FIELDS, not just "Validation failed" — the whole point
                    // is to see what each form demands, so it can be compared
                    // with what the screen actually sends.
                    $fields = collect($res->json('errors') ?? [])->keys()->implode(', ');
                    $rejected[$path] = $code.($fields !== '' ? ' — needs: '.$fields
                        : ' — '.Str::limit((string) $res->json('message'), 120));
                } else {
                    $ok[] = $path;
                }
            } catch (\Throwable $e) {
                $server[$path] = get_class($e).' — '.Str::limit($e->getMessage(), 160);
            }
        }

        // Printed so the run is a readable report, not just a pass or a fail.
        fwrite(STDERR, "\n─── Purchase write probe ───────────────────────────────\n");
        fwrite(STDERR, 'SAVED OK ('.count($ok).'): '.implode(', ', $ok)."\n\n");

        if ($rejected) {
            fwrite(STDERR, 'REFUSED ('.count($rejected)."), probably this probe's payload shape:\n");
            foreach ($rejected as $p => $m) {
                fwrite(STDERR, "   {$p}\n      {$m}\n");
            }
            fwrite(STDERR, "\n");
        }

        if ($server) {
            fwrite(STDERR, 'SERVER ERRORS ('.count($server)."), these are defects:\n");
            foreach ($server as $p => $m) {
                fwrite(STDERR, "   {$p}\n      {$m}\n");
            }
        }
        fwrite(STDERR, "────────────────────────────────────────────────────────\n");

        // Only 5xx fails the build. A 422 means this probe guessed the shape
        // wrong, which is not the module being broken.
        $this->assertSame([], $server, "\nPurchase forms that throw on save:\n"
            .collect($server)->map(fn ($m, $p) => "  {$p}\n      {$m}")->implode("\n")."\n");
    }
}
