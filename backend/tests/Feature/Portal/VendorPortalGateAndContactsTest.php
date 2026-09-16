<?php

namespace Tests\Feature\Portal;

use App\Models\Tenant;
use App\Models\Tpv\TpvContact;
use App\Models\Tpv\TpvGateAttendance;
use App\Models\Tpv\TpvGateScan;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The TPV portal's Gate Log, Attendance and Contacts tabs.
 *
 * All three answered 500 and had done since they were written. Two causes:
 *
 *  - gate-log, gate/stats and attendance queried
 *    `App\Models\Tpv\TpvGateLog`, a class that does not exist. The real models
 *    are TpvGateScan (a badge presented) and TpvGateAttendance (a day on site),
 *    and the column names were wrong too — `worker_id` and `duration_minutes`
 *    against tables that spell them `tpv_worker_id` and `minutes_on_site`.
 *  - contacts ordered by `name`, which tpv_contacts does not have; it stores
 *    first_name and last_name.
 *
 * None of it was visible. Every portal page wraps its fetch in
 * `.catch(() => setRows([]))`, so a 500 renders as an empty list and reads as
 * "you have no contacts" rather than as a failure.
 *
 * There was a second bug hiding behind the first: the portal reuses the ADMIN
 * TpvGateLog component with portalApi injected, so its replies must match the
 * admin's shapes. gateStats answered {on_site, total_today} while the component
 * reads on_site_now, checked_in_today, scans_today and denied_today. Fixing only
 * the 500 would have left four blank cards and looked like a new bug.
 */
class VendorPortalGateAndContactsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private Vendor $vendor;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'TPV Rep', 'role' => 'third_party_vendor',
            'email' => 'tpv-'.Str::random(5).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'user_id' => $this->user->id,
            'company_name' => 'Acme TPV', 'vendor_code' => 'V-'.strtoupper(Str::random(5)),
            'email' => $this->user->email, 'status' => 'Active',
        ]);
    }

    private function worker(?Vendor $vendor = null, string $name = 'Ravi Kumar'): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT,
            'vendor_id' => ($vendor ?? $this->vendor)->id,
            'worker_code' => 'W-'.strtoupper(Str::random(6)),
            'name' => $name, 'status' => 'Active',
        ]);
    }

    /** A second vendor in the same tenant — the isolation control. */
    private function otherVendor(): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival Ltd',
            'vendor_code' => 'V-'.strtoupper(Str::random(5)),
            'email' => 'rival-'.Str::random(4).'@t.local', 'status' => 'Active',
        ]);
    }

    /* ── Gate log ───────────────────────────────────────────────── */

    public function test_the_gate_log_answers_with_this_vendors_scans(): void
    {
        $mine = $this->worker();
        TpvGateScan::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $mine->id,
            'decision' => 'Allowed', 'gate' => 'Main', 'scanned_at' => now(),
        ]);

        Sanctum::actingAs($this->user);
        $rows = $this->getJson('/api/portal/gate-log')->assertOk()->json();

        $this->assertCount(1, $rows, 'the vendor cannot see their own gate scan');
        $this->assertSame($mine->id, $rows[0]['tpv_worker_id']);
    }

    public function test_the_gate_log_hides_another_vendors_scans(): void
    {
        $theirs = $this->worker($this->otherVendor(), 'Someone Else');
        TpvGateScan::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $theirs->id,
            'decision' => 'Allowed', 'gate' => 'Main', 'scanned_at' => now(),
        ]);

        Sanctum::actingAs($this->user);
        $rows = $this->getJson('/api/portal/gate-log')->assertOk()->json();

        // Same tenant, different vendor. A gate log that leaked across vendors
        // would show one contractor who else is on the client's site.
        $this->assertSame([], $rows ?: []);
    }

    /* ── Gate stats — the four cards ────────────────────────────── */

    public function test_the_gate_counters_use_the_keys_the_screen_reads(): void
    {
        $mine = $this->worker();
        TpvGateScan::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $mine->id,
            'decision' => 'Allowed', 'gate' => 'Main', 'scanned_at' => now(),
        ]);
        TpvGateAttendance::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $mine->id,
            'work_date' => today()->toDateString(), 'check_in_at' => now()->subHours(3),
        ]);

        Sanctum::actingAs($this->user);
        $body = $this->getJson('/api/portal/gate/stats')->assertOk()->json();

        // Exactly the four the shared component reads. The old payload used
        // different names, so the cards rendered blank.
        foreach (['scans_today', 'denied_today', 'on_site_now', 'checked_in_today'] as $key) {
            $this->assertArrayHasKey($key, $body, "the Gate Log cards read {$key} and it is missing");
        }

        $this->assertSame(1, $body['scans_today']);
        $this->assertSame(0, $body['denied_today']);
        $this->assertSame(1, $body['on_site_now'], 'checked in and not out is on site');
        $this->assertSame(1, $body['checked_in_today']);
    }

    public function test_the_counters_do_not_count_another_vendors_people(): void
    {
        $theirs = $this->worker($this->otherVendor(), 'Someone Else');
        TpvGateScan::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $theirs->id,
            'decision' => 'Allowed', 'gate' => 'Main', 'scanned_at' => now(),
        ]);
        TpvGateAttendance::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $theirs->id,
            'work_date' => today()->toDateString(), 'check_in_at' => now(),
        ]);

        Sanctum::actingAs($this->user);
        $body = $this->getJson('/api/portal/gate/stats')->assertOk()->json();

        $this->assertSame(0, $body['scans_today']);
        $this->assertSame(0, $body['on_site_now']);
    }

    /* ── Attendance ─────────────────────────────────────────────── */

    public function test_attendance_returns_the_roster_shape_the_screen_expects(): void
    {
        $mine = $this->worker();
        TpvGateAttendance::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $mine->id,
            'work_date' => today()->toDateString(),
            'check_in_at' => now()->subHours(4), 'check_out_at' => now()->subHour(),
            'minutes_on_site' => 180,
        ]);

        Sanctum::actingAs($this->user);
        $body = $this->getJson('/api/portal/attendance')->assertOk()->json();

        $this->assertArrayHasKey('summary', $body);
        $this->assertArrayHasKey('rows', $body);
        $this->assertSame(1, $body['summary']['total']);
        $this->assertSame(1, $body['summary']['departed']);
        $this->assertSame(0, $body['summary']['on_site']);
        // minutes_on_site, not the duration_minutes the old code summed.
        $this->assertSame(180, $body['summary']['total_minutes']);
    }

    public function test_attendance_is_scoped_to_the_vendor(): void
    {
        $theirs = $this->worker($this->otherVendor(), 'Someone Else');
        TpvGateAttendance::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $theirs->id,
            'work_date' => today()->toDateString(), 'check_in_at' => now(),
        ]);

        Sanctum::actingAs($this->user);
        $body = $this->getJson('/api/portal/attendance')->assertOk()->json();

        $this->assertSame(0, $body['summary']['total']);
    }

    /* ── Contacts ───────────────────────────────────────────────── */

    public function test_contacts_answers_with_the_vendors_own_people(): void
    {
        TpvContact::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $this->vendor->id,
            'first_name' => 'Asha', 'last_name' => 'Rao',
            'email' => 'asha@acme.local', 'is_primary' => true, 'status' => 'Active',
        ]);

        Sanctum::actingAs($this->user);
        $rows = $this->getJson('/api/portal/contacts')->assertOk()->json();

        $this->assertCount(1, $rows);
        // The model exposes full_name; there is no `name` column, which is what
        // the old ORDER BY named.
        $this->assertSame('Asha Rao', $rows[0]['full_name'] ?? null);
    }

    public function test_the_primary_contact_is_listed_first(): void
    {
        foreach ([['Zoe', 'Last', false], ['Asha', 'Primary', true]] as [$first, $last, $primary]) {
            TpvContact::create([
                'tenant_id' => self::TENANT, 'vendor_id' => $this->vendor->id,
                'first_name' => $first, 'last_name' => $last,
                'email' => strtolower($first).'@acme.local',
                'is_primary' => $primary, 'status' => 'Active',
            ]);
        }

        Sanctum::actingAs($this->user);
        $rows = $this->getJson('/api/portal/contacts')->assertOk()->json();

        // Same ordering the admin screen uses, so the two agree.
        $this->assertTrue((bool) $rows[0]['is_primary'], 'the primary contact must lead the list');
    }

    public function test_contacts_hides_another_vendors_people(): void
    {
        TpvContact::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $this->otherVendor()->id,
            'first_name' => 'Not', 'last_name' => 'Yours',
            'email' => 'no@rival.local', 'status' => 'Active',
        ]);

        Sanctum::actingAs($this->user);
        $rows = $this->getJson('/api/portal/contacts')->assertOk()->json();

        $this->assertSame([], $rows ?: []);
    }

    /**
     * The ratchet.
     *
     * TpvGateLog never existed, and three call sites referenced it for months
     * because nothing reads a class name until the line runs.
     */
    public function test_the_portal_does_not_reference_a_model_that_does_not_exist(): void
    {
        // Tokenised, not grepped: the comment above gateLog() names the missing
        // class in order to explain it, and a plain text scan reads that as a
        // reference. token_get_all lets the comments say what happened without
        // tripping the guard that stops it happening again.
        $src = file_get_contents(app_path('Http/Controllers/Api/Portal/VendorPortalController.php'));

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        // Every Model class the controller names, checked for existence.
        preg_match_all('/App\\\\Models\\\\[A-Za-z\\\\]+/', $code, $m);

        $ghosts = array_values(array_unique(array_filter(
            $m[0],
            fn ($cls) => ! class_exists($cls),
        )));

        $this->assertSame([], $ghosts,
            'the portal names model classes that do not exist: '.implode(', ', $ghosts));
    }

    /** And the columns those queries name really are on those tables. */
    public function test_the_gate_tables_have_the_columns_the_service_uses(): void
    {
        foreach ([
            'tpv_gate_scans' => ['tpv_worker_id', 'scanned_at', 'decision'],
            'tpv_gate_attendances' => ['tpv_worker_id', 'work_date', 'check_in_at', 'check_out_at', 'minutes_on_site'],
            'tpv_contacts' => ['first_name', 'last_name', 'is_primary'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($table, $column),
                    "{$table}.{$column} is gone — the query that names it will throw at runtime");
            }
        }
    }
}
