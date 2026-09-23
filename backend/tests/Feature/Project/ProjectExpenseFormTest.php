<?php

namespace Tests\Feature\Project;

use App\Models\ExpenseCategory;
use App\Models\Project\Project;
use App\Models\Project\ProjectExpense;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The project expense form, finished.
 *
 * It recorded a title, a free-text category, an amount, a date, a note and a
 * billable flag — enough to say money was spent and not enough to do anything
 * with afterwards. No receipt, so nothing could be claimed or audited. No
 * reference, so it could not be matched to a bill. No payment mode, which the
 * CLIENT expense has always had, so the same spend stored different things
 * depending on which screen recorded it. No tax. And the category was retyped on
 * every row while expense_categories and its admin screen already existed.
 */
class ProjectExpenseFormTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Super Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => self::TENANT, 'name' => 'Warehouse fit-out',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function add(array $data)
    {
        return $this->postJson("/api/projects/{$this->project->id}/expenses", array_merge([
            'title' => 'Site barricades', 'amount' => 1000, 'expense_date' => '2026-03-01',
        ], $data));
    }

    public function test_an_expense_records_everything_the_form_now_asks_for(): void
    {
        $row = $this->add([
            'amount'       => 1000,
            'tax_percent'  => 18,
            'currency'     => 'INR',
            'reference_no' => 'BILL-2291',
            'payment_mode' => 'Bank transfer',
            'note'         => 'Two weeks of hire',
            'billable'     => true,
        ])->assertStatus(201)->json('data');

        $this->assertSame('BILL-2291', $row['reference_no']);
        $this->assertSame('Bank transfer', $row['payment_mode']);
        $this->assertSame('INR', $row['currency']);
        // The gross is computed server-side so the list, the total and the export
        // can never work it out three slightly different ways.
        $this->assertEquals(1180.0, $row['total_amount']);
    }

    public function test_a_chosen_category_is_stored_by_id_and_by_name(): void
    {
        $cat = ExpenseCategory::create(['tenant_id' => self::TENANT, 'name' => 'Travel', 'active' => true]);

        $row = $this->add(['expense_category_id' => $cat->id])->assertStatus(201)->json('data');

        // The id is the truth — "Travel", "travel" and "Travel " were three
        // categories when this was free text, and no total could be trusted.
        $this->assertSame($cat->id, $row['expense_category_id']);
        // The label is kept too, so renaming or removing the category later does
        // not blank the category on every historical row.
        $this->assertSame('Travel', $row['category']);
    }

    public function test_a_chosen_category_wins_over_whatever_was_typed(): void
    {
        $cat = ExpenseCategory::create(['tenant_id' => self::TENANT, 'name' => 'Travel', 'active' => true]);

        $row = $this->add(['expense_category_id' => $cat->id, 'category' => 'typo'])
            ->assertStatus(201)->json('data');

        $this->assertSame('Travel', $row['category']);
    }

    public function test_a_category_can_still_be_typed_when_there_is_no_master_entry(): void
    {
        // A taxi fare should not need an admin to create a category first.
        $row = $this->add(['category' => 'Sundries'])->assertStatus(201)->json('data');

        $this->assertSame('Sundries', $row['category']);
        $this->assertNull($row['expense_category_id']);
    }

    public function test_a_category_from_another_tenant_is_refused(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $foreign = ExpenseCategory::create(['tenant_id' => 2, 'name' => 'Theirs', 'active' => true]);

        // exists:expense_categories,id passes — the row is real. Only the tenant
        // check in the service stops it, which is why that check is there.
        $this->add(['expense_category_id' => $foreign->id])->assertStatus(422);

        $this->assertSame(0, ProjectExpense::count());
    }

    public function test_editing_keeps_the_new_fields_instead_of_dropping_them(): void
    {
        $id = $this->add(['reference_no' => 'BILL-1', 'payment_mode' => 'Cash'])
            ->assertStatus(201)->json('data.id');

        // The bug this guards: create and update had two separate field lists, so
        // a field added to one silently vanished on the other.
        $row = $this->putJson("/api/projects/{$this->project->id}/expenses/{$id}", [
            'reference_no' => 'BILL-2', 'payment_mode' => 'UPI', 'tax_percent' => 5,
        ])->assertOk()->json('data');

        $this->assertSame('BILL-2', $row['reference_no']);
        $this->assertSame('UPI', $row['payment_mode']);
        $this->assertEquals(1050.0, $row['total_amount']);
    }

    /* ── the receipt ────────────────────────────────────────────── */

    public function test_a_receipt_can_be_attached_and_read_back(): void
    {
        Storage::fake('local');

        $id = $this->add([])->assertStatus(201)->json('data.id');

        $row = $this->post("/api/projects/{$this->project->id}/expenses/{$id}/receipt", [
            'receipt' => UploadedFile::fake()->create('bill.pdf', 12, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertTrue($row['has_receipt']);
        $this->assertSame('bill.pdf', $row['receipt_name']);

        // The stored path is a private disk location, never a URL — sending it to
        // the browser would invite somebody to try fetching it directly.
        $this->assertArrayNotHasKey('receipt_path', $row);

        $this->get("/api/projects/{$this->project->id}/expenses/{$id}/receipt")->assertOk();
    }

    public function test_replacing_a_receipt_removes_the_old_file(): void
    {
        Storage::fake('local');

        $id = $this->add([])->assertStatus(201)->json('data.id');

        $this->post("/api/projects/{$this->project->id}/expenses/{$id}/receipt", [
            'receipt' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();
        $first = ProjectExpense::find($id)->receipt_path;

        $this->post("/api/projects/{$this->project->id}/expenses/{$id}/receipt", [
            'receipt' => UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        // A receipt is a document somebody may be required to produce, so it is
        // either the one on the row or gone — never an orphan on disk.
        Storage::disk('local')->assertMissing($first);
        $this->assertSame('second.pdf', ProjectExpense::find($id)->receipt_name);
    }

    public function test_something_that_is_not_a_receipt_is_refused(): void
    {
        Storage::fake('local');
        $id = $this->add([])->assertStatus(201)->json('data.id');

        $this->post("/api/projects/{$this->project->id}/expenses/{$id}/receipt", [
            'receipt' => UploadedFile::fake()->create('payload.exe', 10),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_downloading_a_receipt_that_is_not_there_is_a_404(): void
    {
        $id = $this->add([])->assertStatus(201)->json('data.id');

        $this->getJson("/api/projects/{$this->project->id}/expenses/{$id}/receipt")->assertStatus(404);
    }

    public function test_an_expense_on_another_tenants_project_is_not_reachable(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $foreign = Project::create([
            'tenant_id' => 2, 'name' => 'Someone elses',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);

        $this->postJson("/api/projects/{$foreign->id}/expenses", [
            'title' => 'Sneaky', 'amount' => 1, 'expense_date' => '2026-03-01',
        ])->assertStatus(404);
    }
}
