<?php

namespace Tests\Feature\Purchase;

use App\Models\Project\Project;
use App\Models\Purchase\PurchaseDebitNote;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Purchase\PurchaseWorkerTraining;
use App\Models\Shared\Attachment;
use App\Models\Shared\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Purchase Vendor detail workspace — the tabs that hang off one vendor.
 *
 * Purchase and TPV share no table, but they DO share three polymorphic engines
 * (notes, reminders, attachments), the appointments table, and the project link
 * that Expenses and Tickets hop through. That sharing is where the risk is, so
 * this pins down the two things that could go wrong:
 *
 *  1. the vendor in the URL is load-bearing — a note/file/reminder/appointment id
 *     from elsewhere in the tenant must 404, not resolve;
 *  2. the two vendor modules must not see each other. projects.vendor_id is only
 *     unique WITHIN a link_type, so the same integer is a different company under
 *     'tpv_vendor' and 'purchase_vendor' — a dropped link_type predicate would
 *     silently show one module's data on the other's screen.
 */
class PurchaseVendorWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');

        foreach ([self::TENANT, 2] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "T{$id}", 'slug' => "t{$id}",
                'subdomain' => "t{$id}", 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role, int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function pv(string $name, int $tenant = self::TENANT): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(4).'@pv.local',
            'purchase_vendor_code' => 'PUR-'.Str::random(6), 'status' => 'Active',
        ]);
    }

    private function base(PurchaseVendor $v): string
    {
        return "/api/purchase/vendors/{$v->id}";
    }

    /** projects requires start_date and created_by, so both are filled here. */
    private function project(string $name, string $linkType, int $vendorId, User $actor): Project
    {
        return Project::create([
            'tenant_id'  => self::TENANT, 'name' => $name, 'status' => 'in_progress',
            'link_type'  => $linkType, 'vendor_id' => $vendorId,
            'start_date' => now()->toDateString(), 'created_by' => $actor->id,
        ]);
    }

    /* ── 1. Tenant isolation ──────────────────────────────────────────── */

    public function test_a_vendor_from_another_tenant_is_not_found(): void
    {
        $foreign = $this->pv('ForeignCo', 2);
        Sanctum::actingAs($this->user('staff'));

        // Route-model binding resolves any tenant's row — assertTenant is the
        // only guard, so every tab endpoint has to call it.
        foreach (['notes', 'reminders', 'attachments', 'appointments', 'payments', 'statement'] as $tab) {
            $this->getJson($this->base($foreign)."/{$tab}")
                ->assertStatus(404, "{$tab} leaked a foreign tenant's vendor");
        }
    }

    /* ── 2. Unknown vendor ────────────────────────────────────────────── */

    public function test_an_unknown_vendor_is_not_found(): void
    {
        Sanctum::actingAs($this->user('staff'));

        $this->getJson('/api/purchase/vendors/999999/notes')->assertStatus(404);
        $this->getJson('/api/purchase/vendors/999999/statement')->assertStatus(404);
    }

    /* ── 3. Empty vendor ──────────────────────────────────────────────── */

    public function test_a_vendor_with_no_records_returns_empty_not_everything(): void
    {
        $v = $this->pv('EmptyCo');
        Sanctum::actingAs($this->user('staff'));

        $this->getJson($this->base($v).'/notes')->assertOk()->assertJsonCount(0);
        $this->getJson($this->base($v).'/reminders')->assertOk()->assertJsonCount(0);
        $this->getJson($this->base($v).'/appointments')->assertOk()->assertJsonCount(0);
        $this->getJson($this->base($v).'/payments')->assertOk()->assertJsonCount(0);
        $this->getJson($this->base($v).'/attachments')->assertOk()
            ->assertJsonCount(0, 'files')->assertJsonCount(0, 'folders');
        $this->getJson($this->base($v).'/statement')->assertOk()
            ->assertJsonPath('closing_balance', 0)->assertJsonCount(0, 'lines');
    }

    /* ── 4. Notes / reminders / attachments: create + cross-vendor ────── */

    public function test_notes_are_scoped_to_the_vendor_in_the_route(): void
    {
        $alpha = $this->pv('AlphaCo');
        $beta  = $this->pv('BetaCo');
        Sanctum::actingAs($this->user('staff'));

        $id = $this->postJson($this->base($alpha).'/notes', ['title' => 'Alpha only'])
            ->assertStatus(201)->json('id');

        // Written against PurchaseVendor, not Vendor — the copy-paste hazard.
        $this->assertSame(PurchaseVendor::class, Note::find($id)->notable_type);

        $this->getJson($this->base($alpha).'/notes')->assertOk()->assertJsonCount(1);
        $this->getJson($this->base($beta).'/notes')->assertOk()->assertJsonCount(0);

        $this->putJson($this->base($beta)."/notes/{$id}", ['title' => 'Hijacked'])->assertStatus(404);
        $this->deleteJson($this->base($beta)."/notes/{$id}")->assertStatus(404);
        $this->assertSame('Alpha only', Note::find($id)->title);
    }

    public function test_a_tpv_vendors_note_is_not_reachable_through_a_purchase_vendor(): void
    {
        // Same integer id, different subject type — the sharpest case for a
        // polymorphic table.
        $tpv = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'TpvCo',
            'email' => 'tpv@v.local', 'status' => VendorStatus::ACTIVE,
        ]);
        $pv = $this->pv('PurchaseCo');

        Sanctum::actingAs($this->user('staff'));

        $tpvNote = $this->postJson("/api/tpv/vendors/{$tpv->id}/notes", ['title' => 'TPV only'])
            ->assertStatus(201)->json('id');

        $this->getJson($this->base($pv).'/notes')->assertOk()->assertJsonCount(0);
        $this->putJson($this->base($pv)."/notes/{$tpvNote}", ['title' => 'Crossed'])->assertStatus(404);
        $this->assertSame('TPV only', Note::find($tpvNote)->title);
    }

    public function test_reminders_are_scoped_and_completable(): void
    {
        $alpha = $this->pv('AlphaCo');
        $beta  = $this->pv('BetaCo');
        Sanctum::actingAs($this->user('staff'));

        $id = $this->postJson($this->base($alpha).'/reminders', [
            'type' => 'call', 'title' => 'Chase PO', 'due_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(201)->json('id');

        $this->postJson($this->base($beta)."/reminders/{$id}/complete", [])->assertStatus(404);
        $this->postJson($this->base($alpha)."/reminders/{$id}/complete", ['outcome' => 'Done'])->assertOk();

        $this->assertNotNull(\App\Models\Sales\Reminder::find($id)->completed_at);
    }

    public function test_attachments_are_scoped_and_bytes_are_stored_here(): void
    {
        $alpha = $this->pv('AlphaCo');
        $beta  = $this->pv('BetaCo');
        Sanctum::actingAs($this->user('staff'));

        $folder = $this->postJson($this->base($alpha).'/attachment-folders', ['name' => 'Contracts'])
            ->assertStatus(201)->json('id');

        $file = $this->post($this->base($alpha).'/attachments', [
            'file' => UploadedFile::fake()->create('terms.pdf', 8, 'application/pdf'),
            'folder_id' => $folder,
        ], ['Accept' => 'application/json'])->assertStatus(201)->json('id');

        $row = Attachment::find($file);
        $this->assertSame(PurchaseVendor::class, $row->attachable_type);
        Storage::disk('attachments')->assertExists($row->path);

        // Cross-vendor: the folder is not a valid target and the file is not reachable.
        $this->post($this->base($beta).'/attachments', [
            'file' => UploadedFile::fake()->create('x.pdf', 4), 'folder_id' => $folder,
        ], ['Accept' => 'application/json'])->assertStatus(404);

        $this->getJson($this->base($beta)."/attachments/{$file}/download")->assertStatus(404);
        $this->deleteJson($this->base($beta)."/attachments/{$file}")->assertStatus(404);
        $this->deleteJson($this->base($beta)."/attachment-folders/{$folder}")->assertStatus(404);
    }

    /* ── 5. Appointments ──────────────────────────────────────────────── */

    public function test_appointments_are_scoped_to_the_vendor_and_gated(): void
    {
        $alpha = $this->pv('AlphaCo');
        $beta  = $this->pv('BetaCo');
        Sanctum::actingAs($this->user('staff'));

        $id = $this->postJson($this->base($alpha).'/appointments', [
            'title' => 'Site visit', 'starts_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(201)->json('id');

        $this->getJson($this->base($alpha).'/appointments')->assertOk()->assertJsonCount(1);
        $this->getJson($this->base($beta).'/appointments')->assertOk()->assertJsonCount(0);

        $this->patchJson($this->base($beta)."/appointments/{$id}/complete", ['outcome' => 'x'])->assertStatus(404);
        $this->patchJson($this->base($alpha)."/appointments/{$id}/complete", ['outcome' => 'Met on site'])->assertOk();
    }

    /* ── 6. Payments derived through invoices ─────────────────────────── */

    public function test_payments_are_derived_from_this_vendors_invoices_only(): void
    {
        $alpha = $this->pv('AlphaCo');
        $beta  = $this->pv('BetaCo');

        $inv = PurchaseInvoice::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $alpha->id,
            'invoice_number' => 'INV-A', 'invoice_date' => now()->subDays(3)->toDateString(),
            'total' => 1000, 'status' => 'Approved',
        ]);
        PurchaseInvoicePayment::create([
            'tenant_id' => self::TENANT, 'purchase_invoice_id' => $inv->id,
            'amount' => 400, 'payment_date' => now()->subDay()->toDateString(),
            'payment_mode' => 'bank_transfer', 'reference' => 'NEFT-1',
        ]);

        Sanctum::actingAs($this->user('staff'));

        $this->getJson($this->base($alpha).'/payments')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.reference', 'NEFT-1');

        // Beta shares the tenant but not the invoice.
        $this->getJson($this->base($beta).'/payments')->assertOk()->assertJsonCount(0);
    }

    /* ── 7. Statement correctness ─────────────────────────────────────── */

    public function test_the_statement_debits_invoices_and_credits_payments_and_debit_notes(): void
    {
        $v = $this->pv('LedgerCo');

        $inv = PurchaseInvoice::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id,
            'invoice_number' => 'INV-1', 'invoice_date' => now()->subDays(5)->toDateString(),
            'total' => 1000, 'status' => 'Approved',
        ]);
        PurchaseInvoicePayment::create([
            'tenant_id' => self::TENANT, 'purchase_invoice_id' => $inv->id,
            'amount' => 400, 'payment_date' => now()->subDays(2)->toDateString(), 'payment_mode' => 'cash',
        ]);
        PurchaseDebitNote::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id,
            'debit_number' => 'DN-1', 'debit_date' => now()->subDay()->toDateString(),
            'total' => 100, 'status' => 'Issued',
        ]);

        Sanctum::actingAs($this->user('staff'));

        // 1000 invoiced − 400 paid − 100 debited = 500.
        $body = $this->getJson($this->base($v).'/statement')->assertOk()->json();

        $this->assertCount(3, $body['lines']);
        $this->assertSame(500.0, (float) $body['closing_balance']);
        // Oldest first, and the running balance walks with it.
        $this->assertSame(['Invoice', 'Payment', 'Debit Note'], array_column($body['lines'], 'type'));
        $this->assertSame(1000.0, (float) $body['lines'][0]['balance']);
        $this->assertSame(600.0, (float) $body['lines'][1]['balance']);
    }

    /* ── 8. Medical / Training ────────────────────────────────────────── */

    public function test_medical_and_training_list_only_this_vendors_records(): void
    {
        $alpha = $this->pv('AlphaCo');
        $beta  = $this->pv('BetaCo');

        $worker = PurchaseWorker::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $alpha->id,
            'worker_code' => 'PW-1', 'full_name' => 'Ramesh', 'status' => 'Draft',
        ]);
        PurchaseWorkerMedical::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $alpha->id,
            'purchase_worker_id' => $worker->id, 'exam_date' => now()->toDateString(),
            'fitness_status' => 'Fit',
        ]);
        PurchaseWorkerTraining::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $alpha->id,
            'purchase_worker_id' => $worker->id, 'title' => 'Site Safety',
        ]);

        Sanctum::actingAs($this->user('staff'));

        $this->getJson("/api/purchase/workforce/medicals?vendor_id={$alpha->id}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/purchase/workforce/trainings?vendor_id={$alpha->id}")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson("/api/purchase/workforce/medicals?vendor_id={$beta->id}")
            ->assertOk()->assertJsonCount(0, 'data');

        // Strict: no vendor means no data, never the tenant's whole workforce.
        $this->getJson('/api/purchase/workforce/medicals')->assertStatus(422);
    }

    /* ── 9. Project / Expenses / Ticket hop ───────────────────────────── */

    public function test_the_two_vendor_modules_never_see_each_others_projects(): void
    {
        // The same integer under two link types is two different companies.
        $shared = 7;
        $admin  = $this->user('admin');

        $this->project('Purchase job', 'purchase_vendor', $shared, $admin);
        $this->project('TPV job', 'tpv_vendor', $shared, $admin);

        Sanctum::actingAs($admin);

        $purchase = $this->getJson("/api/projects?vendor_id={$shared}&vendor_type=purchase_vendor")
            ->assertOk()->json('data');
        $this->assertCount(1, $purchase);
        $this->assertSame('Purchase job', $purchase[0]['name']);

        // Omitting vendor_type must keep meaning TPV, or every existing caller
        // silently changes behaviour.
        $tpv = $this->getJson("/api/projects?vendor_id={$shared}")->assertOk()->json('data');
        $this->assertCount(1, $tpv);
        $this->assertSame('TPV job', $tpv[0]['name']);
    }

    public function test_an_unknown_vendor_type_is_refused_rather_than_defaulted(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/projects?vendor_id=1&vendor_type=nonsense')->assertStatus(422);
        $this->getJson('/api/helpdesk/tickets?vendor_id=1&vendor_type=nonsense')->assertStatus(422);
    }

    public function test_link_type_purchase_vendor_survives_the_database_round_trip(): void
    {
        // The column was string(12); 'purchase_vendor' is 15. On a narrow column
        // this truncates and the filter silently matches nothing.
        $p = $this->project('Width check', 'purchase_vendor', 42, $this->user('admin'));

        $this->assertSame('purchase_vendor', $p->fresh()->link_type);
    }

    /* ── 10. Role gates ───────────────────────────────────────────────── */

    public function test_portal_and_wrong_role_logins_cannot_reach_the_workspace(): void
    {
        $v = $this->pv('AlphaCo');

        foreach (['third_party_vendor', 'client', 'employee'] as $role) {
            Sanctum::actingAs($this->user($role));

            foreach (['notes', 'reminders', 'attachments', 'appointments', 'payments', 'statement'] as $tab) {
                $this->getJson($this->base($v)."/{$tab}")
                    ->assertStatus(403, "{$tab} must refuse a {$role} login");
            }
        }
    }

    public function test_staff_and_admin_both_reach_the_workspace(): void
    {
        $v = $this->pv('AlphaCo');

        foreach (['admin', 'staff'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson($this->base($v).'/notes')->assertOk();
            $this->getJson($this->base($v).'/statement')->assertOk();
        }
    }

    /* ── 11. Existing behaviour unchanged ─────────────────────────────── */

    public function test_the_existing_purchase_vendor_endpoints_are_untouched(): void
    {
        $v = $this->pv('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $this->getJson('/api/purchase/vendors')->assertOk();
        $this->getJson("/api/purchase/vendors/{$v->id}")->assertOk();
        $this->getJson("/api/purchase/vendors/{$v->id}/tasks")->assertOk();
    }
}
