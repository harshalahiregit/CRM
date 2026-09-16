<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every field the app's advance form posts has to survive the round trip.
 *
 * `department` did not. The app posts it, the request validated it, and
 * AdvanceService::request() then never copied it because no column existed — so
 * whatever somebody typed was accepted and thrown away. The response hid it by
 * echoing the EMPLOYEE's department instead, which meant the screen showed a
 * plausible value that was never the one entered: the worst shape of this bug,
 * because nothing looks wrong.
 *
 * Driven through the HTTP surface as multipart/form-data, because that is how
 * the app actually sends it — a JSON-only test would not have caught the web
 * client silently sending nothing at all.
 */
class HrmAdvanceFieldsTest extends TestCase
{
    use RefreshDatabase;

    private HrEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'adv-fields-t', 'status' => 'active']);

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Priya', 'email' => 'priya@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        $this->employee = HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'SNE-1', 'name' => 'Priya',
            'department' => 'Management', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $user->id, 'app_login_enabled' => true,
        ]);

        Sanctum::actingAs($user);
    }

    private const POSTED = [
        'workspace_id'             => '1',
        'advance_type'             => 'site_cash',
        'category'                 => 'site_operations',
        'department'               => 'Operations',
        'project_site'             => 'Pune Plant',
        'purpose'                  => 'Vendor site visit - advance for travel',
        'amount_requested'         => '18000',
        'required_date'            => '2026-09-10',
        'expected_settlement_date' => '2026-09-30',
    ];

    public function test_every_field_the_form_posts_is_stored(): void
    {
        $r = $this->post('/api/Hrm/advance/submit', self::POSTED);

        $r->assertOk();
        $this->assertSame(1, $r->json('status'), (string) $r->json('message'));

        $a = HrAdvance::latest('id')->first();

        $this->assertNotNull($a, 'Nothing was created.');
        $this->assertSame('site_cash', $a->advance_type);
        $this->assertSame('site_operations', $a->category);
        $this->assertSame('Operations', $a->department, 'The typed department was discarded.');
        $this->assertSame('Pune Plant', $a->project_site);
        $this->assertSame('Vendor site visit - advance for travel', $a->purpose);
        $this->assertSame('18000.00', (string) $a->amount_requested);
        $this->assertSame('2026-09-10', $a->required_date->format('Y-m-d'));
        $this->assertSame('2026-09-30', $a->expected_settlement_date->format('Y-m-d'));
    }

    public function test_the_department_read_back_is_the_one_that_was_entered(): void
    {
        $this->post('/api/Hrm/advance/submit', self::POSTED)->assertOk();

        $row = collect(
            $this->postJson('/api/Hrm/advance/my-requests', ['workspace_id' => '1'])
                ->assertOk()->json('data')
        )->first();

        $this->assertSame('Operations', $row['department']);
        $this->assertSame('Pune Plant', $row['project_site']);
    }

    /** Left blank, the employee's own department is the sensible answer. */
    public function test_a_blank_department_falls_back_to_the_employees(): void
    {
        $posted = self::POSTED;
        unset($posted['department']);

        $this->post('/api/Hrm/advance/submit', $posted)->assertOk();

        $row = collect(
            $this->postJson('/api/Hrm/advance/my-requests', ['workspace_id' => '1'])
                ->assertOk()->json('data')
        )->first();

        $this->assertSame('Management', $row['department']);
    }
}
