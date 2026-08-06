<?php

namespace Tests\Feature\SangoeTrack;

use App\Models\Hr\HrEmployee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The importer reads SangoeTrack's own `employees` table directly, so these
 * tests build that table for real rather than mocking a client — the SQL, the
 * workspace/is_active filter and the FK lookups are all exercised.
 *
 * The connection name is config-driven precisely so it can be pointed at the
 * test database here instead of a live MySQL host.
 */
class SangoeTrackImportEmployeesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Point the SangoeTrack connection at the test database, then build
        // SangoeTrack's schema inside it.
        config([
            'sangoetrack.db.connection' => config('database.default'),
            'sangoetrack.db.workspace'  => 1,
            'sangoetrack.employee_key'  => 'user_id',
        ]);

        Schema::create('employees', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('employee_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->unsignedBigInteger('designation_id')->nullable();
            $t->tinyInteger('is_active')->default(1);
            $t->unsignedBigInteger('workspace')->default(1);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('departments', function ($t) {
            $t->id();
            $t->string('name');
        });

        Schema::create('designations', function ($t) {
            $t->id();
            $t->string('name');
        });
    }

    private function remote(array $attrs = []): int
    {
        return DB::table('employees')->insertGetId(array_merge([
            'user_id'    => 55,
            'name'       => 'Asha Menon',
            'email'      => 'asha@sangoe.in',
            'is_active'  => 1,
            'workspace'  => 1,
            'created_at' => '2024-04-01 10:00:00',
            'updated_at' => '2024-04-01 10:00:00',
        ], $attrs));
    }

    private function import(array $args = []): int
    {
        return $this->artisan('sangoetrack:import-employees', array_merge(['--tenant' => self::TENANT], $args))->run();
    }

    /* ── create ──────────────────────────────────────────────────────── */

    public function test_it_creates_employees_from_the_sangoetrack_table(): void
    {
        DB::table('departments')->insert(['id' => 3, 'name' => 'Engineering']);
        DB::table('designations')->insert(['id' => 9, 'name' => 'Senior Developer']);

        $this->remote(['department_id' => 3, 'designation_id' => 9, 'employee_id' => 'EMP-0007']);
        $this->remote(['user_id' => 56, 'name' => 'Rahul Rao', 'email' => 'rahul@sangoe.in']);

        $this->import();

        $this->assertSame(2, HrEmployee::count());

        $asha = HrEmployee::where('email', 'asha@sangoe.in')->firstOrFail();
        $this->assertSame('Asha Menon', $asha->name);
        $this->assertSame(55, (int) $asha->sangoetrack_user_id);
        $this->assertSame(1, (int) $asha->sangoetrack_workspace_id);
        $this->assertSame('sangoetrack', $asha->source);
        $this->assertSame('EMP-0007', $asha->employee_code);
        // FK ids resolved to labels via the lookup tables.
        $this->assertSame('Engineering', $asha->department);
        $this->assertSame('Senior Developer', $asha->designation);
    }

    /* ── the WHERE clause ────────────────────────────────────────────── */

    public function test_inactive_and_other_workspace_rows_are_excluded(): void
    {
        $this->remote();                                                          // in scope
        $this->remote(['user_id' => 60, 'email' => 'inactive@sangoe.in', 'is_active' => 0]);
        $this->remote(['user_id' => 61, 'email' => 'ws2@sangoe.in', 'workspace' => 2]);

        $this->import();

        $this->assertSame(1, HrEmployee::count());
        $this->assertSame('asha@sangoe.in', HrEmployee::firstOrFail()->email);
    }

    public function test_the_workspace_can_be_overridden(): void
    {
        $this->remote(['user_id' => 61, 'email' => 'ws2@sangoe.in', 'workspace' => 2]);

        $this->import(['--workspace' => 2]);

        $this->assertSame(1, HrEmployee::count());
        $this->assertSame(2, (int) HrEmployee::firstOrFail()->sangoetrack_workspace_id);
    }

    /* ── mapping fallbacks ───────────────────────────────────────────── */

    public function test_unresolvable_lookups_fall_back_to_defaults(): void
    {
        $this->remote(['department_id' => 999, 'designation_id' => null]);

        $this->import();

        $emp = HrEmployee::firstOrFail();
        $this->assertSame('Unassigned', $emp->department);
        $this->assertSame('Unassigned', $emp->designation);
        $this->assertSame('Active', $emp->status);
    }

    /** SangoeTrack has no joining date, so created_at is the proxy. */
    public function test_joining_date_falls_back_to_created_at(): void
    {
        $this->remote(['created_at' => '2023-06-15 09:00:00']);

        $this->import();

        $this->assertSame('2023-06-15', HrEmployee::firstOrFail()->joining_date->toDateString());
    }

    public function test_employee_code_is_derived_and_stable_without_a_remote_code(): void
    {
        $id = $this->remote(['employee_id' => null]);

        $this->import();
        $first = HrEmployee::firstOrFail()->employee_code;

        $this->import();

        $this->assertSame('ST-'.$id, $first);
        $this->assertSame($first, HrEmployee::firstOrFail()->employee_code);
    }

    /** employees.user_id is the login; employees.id is the roster row. */
    public function test_the_external_key_column_is_configurable(): void
    {
        $id = $this->remote(['user_id' => 55]);

        config(['sangoetrack.employee_key' => 'id']);
        $this->import();

        $this->assertSame($id, (int) HrEmployee::firstOrFail()->sangoetrack_user_id);
    }

    /* ── identity / safety ───────────────────────────────────────────── */

    public function test_rows_without_a_usable_email_are_skipped(): void
    {
        $this->remote(['user_id' => 57, 'email' => null]);
        $this->remote(['user_id' => 58, 'email' => 'not-an-email']);
        $this->remote(['user_id' => 59, 'email' => 'good@sangoe.in']);

        $this->import();

        $this->assertSame(1, HrEmployee::count());
        $this->assertSame('good@sangoe.in', HrEmployee::firstOrFail()->email);
    }

    public function test_tenant_is_required_for_a_real_import(): void
    {
        $this->remote();

        $exit = $this->artisan('sangoetrack:import-employees')->run();

        $this->assertSame(1, $exit);
        $this->assertSame(0, HrEmployee::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->remote();

        $this->import(['--dry-run' => true]);

        $this->assertSame(0, HrEmployee::count());
    }

    /** A dry run is allowed without --tenant so the roster can be inspected. */
    public function test_dry_run_without_tenant_is_allowed(): void
    {
        $this->remote();

        $exit = $this->artisan('sangoetrack:import-employees', ['--dry-run' => true])->run();

        $this->assertSame(0, $exit);
        $this->assertSame(0, HrEmployee::count());
    }

    /* ── idempotency ─────────────────────────────────────────────────── */

    public function test_rerunning_creates_no_duplicates(): void
    {
        $this->remote();
        $this->remote(['user_id' => 56, 'name' => 'Rahul Rao', 'email' => 'rahul@sangoe.in']);

        $this->import();
        $this->import();
        $this->import();

        $this->assertSame(2, HrEmployee::count());
    }

    /* ── email-match update path ─────────────────────────────────────── */

    public function test_an_existing_employee_is_linked_not_duplicated(): void
    {
        $existing = HrEmployee::create([
            'tenant_id' => self::TENANT, 'name' => 'Asha Menon', 'email' => 'asha@sangoe.in',
            'employee_code' => 'MAN-001', 'department' => 'Engineering', 'designation' => 'Developer',
            'status' => 'Active', 'joining_date' => '2020-01-01',
        ]);

        $this->remote();
        $this->import();

        $this->assertSame(1, HrEmployee::count());
        $existing->refresh();
        $this->assertSame(55, (int) $existing->sangoetrack_user_id);
        // A pre-existing record keeps its identity.
        $this->assertSame('MAN-001', $existing->employee_code);
        $this->assertNull($existing->source);
    }

    public function test_matching_uses_official_email_and_ignores_case(): void
    {
        $existing = HrEmployee::create([
            'tenant_id' => self::TENANT, 'name' => 'Asha', 'email' => 'personal@gmail.com',
            'official_email' => 'Asha@Sangoe.in', 'employee_code' => 'MAN-002',
            'department' => 'Eng', 'designation' => 'Dev', 'status' => 'Active', 'joining_date' => '2020-01-01',
        ]);

        $this->remote(['email' => 'ASHA@sangoe.in']);
        $this->import();

        $this->assertSame(1, HrEmployee::count());
        $this->assertSame(55, (int) $existing->refresh()->sangoetrack_user_id);
    }

    public function test_existing_fields_are_only_refreshed_when_asked(): void
    {
        DB::table('departments')->insert(['id' => 3, 'name' => 'Finance']);

        $existing = HrEmployee::create([
            'tenant_id' => self::TENANT, 'name' => 'Old Name', 'email' => 'asha@sangoe.in',
            'employee_code' => 'MAN-003', 'department' => 'Engineering', 'designation' => 'Developer',
            'status' => 'Active', 'joining_date' => '2020-01-01',
        ]);

        $this->remote(['name' => 'New Name', 'department_id' => 3]);

        $this->import();
        $this->assertSame('Old Name', $existing->refresh()->name, 'default import must not overwrite CRM data');

        $this->import(['--update-existing' => true]);
        $existing->refresh();
        $this->assertSame('New Name', $existing->name);
        $this->assertSame('Finance', $existing->department);
    }

    public function test_import_is_scoped_to_the_given_tenant(): void
    {
        HrEmployee::create([
            'tenant_id' => 2, 'name' => 'Other Tenant Asha', 'email' => 'asha@sangoe.in',
            'employee_code' => 'T2-001', 'department' => 'Eng', 'designation' => 'Dev',
            'status' => 'Active', 'joining_date' => '2020-01-01',
        ]);

        $this->remote();
        $this->import();

        // Same email in another tenant is a different person.
        $this->assertSame(2, HrEmployee::count());
        $this->assertSame(1, HrEmployee::where('tenant_id', self::TENANT)->count());
        $this->assertNull(HrEmployee::where('tenant_id', 2)->firstOrFail()->sangoetrack_user_id);
    }

    /* ── failure surfaces ────────────────────────────────────────────── */

    public function test_an_empty_roster_is_a_failure_not_a_silent_success(): void
    {
        $this->assertSame(1, $this->import());
        $this->assertSame(0, HrEmployee::count());
    }

    public function test_an_unreachable_connection_fails_with_a_useful_message(): void
    {
        config(['sangoetrack.db.connection' => 'sangoetrack']);
        config(['database.connections.sangoetrack' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1',
            'database' => 'nope', 'username' => 'nope', 'password' => null,
        ]]);

        $this->artisan('sangoetrack:import-employees', ['--tenant' => self::TENANT])
            ->expectsOutputToContain('SANGOETRACK_DB_PASSWORD')
            ->assertExitCode(1);
    }
}
