<?php

namespace Tests\Feature\Auth;

use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\StaffPermissionService;
use App\Services\Auth\StaffRoleService;
use App\Support\Hr\DataScope;
use App\Support\Hr\StaffPermission;
use App\Support\Hr\StaffRoleTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One table owns the staff-role vocabulary, and a row may grant nothing.
 *
 * users.internal_role was doing two jobs — the job somebody holds, and, through
 * a dozen hardcoded checks, what they may do. Two tables grew around that.
 * staff_roles said what a role may DO; access_roles said which role NAMES are
 * legal and nothing else. Both wrote the same column, and only one of them
 * carried permissions, so the other could never be the authority: a role
 * manager that granted nothing, with a routed screen and no rows.
 *
 * staff_roles now holds both halves. `is_vocabulary_only` is how it says "this
 * is a legal name, and that is all it is" — which is exactly what `hr` and
 * `manager` are. routes/sangoetrack.php gates 32 routes on
 * role:admin,hr,manager, and EnsureUserHasRole matches those names against
 * users.internal_role as plain strings. Neither was a staff_roles slug, so the
 * vocabulary a live gate depends on was written down only in a table nothing
 * read.
 *
 * The column is NOT a permission bypass and these tests exist mostly to keep it
 * from becoming one.
 */
class StaffRoleArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Arch', 'slug' => 'role-architecture', 'status' => 'active',
        ]);
    }

    private function seeded(): void
    {
        app(StaffRoleService::class)->ensureSeeded($this->tenant->id);
    }

    private function user(string $accountType, string $email, ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $accountType,
            'internal_role' => $internal, 'status' => 'active',
        ]);
    }

    /* ── the column ───────────────────────────────────────────────────── */

    public function test_an_ordinary_role_is_not_vocabulary_only(): void
    {
        $role = app(StaffRoleService::class)->create($this->tenant->id, [
            'name' => 'Senior HR Executive',
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
        ]);

        $this->assertFalse($role->fresh()->is_vocabulary_only,
            'The column exists so that "grants nothing" is stated, not guessed.');
    }

    /**
     * Every ordinary role still carries exactly what its template says, and
     * nothing about it moved.
     *
     * Asserted against the template rather than against numbers measured on a
     * live database, because those two legitimately disagree: ensureSeeded() is
     * CREATE-ONLY by design — it must never overwrite an edit an admin made — so
     * a row seeded months ago keeps whatever the template said then. The live
     * team_lead is one such row (appointments: 2, template: 3, seeded 2 Sep).
     * That drift predates this phase and is not something to assert away.
     */
    public function test_every_pre_existing_seeded_role_keeps_its_permissions_and_scope(): void
    {
        $this->seeded();

        foreach (StaffRoleTemplate::DEFINITIONS as $slug => $def) {
            $role = StaffRole::where('tenant_id', $this->tenant->id)->where('slug', $slug)->firstOrFail();

            $this->assertSame(
                StaffPermission::sanitise($def['permissions']),
                $role->grants(),
                "{$slug} does not carry what its template grants."
            );
            $this->assertSame(DataScope::GLOBAL, $role->scope, "{$slug}'s Phase 1 scope must be untouched.");

            // The two vocabulary entries are the only ones that grant nothing.
            $isVocabulary = (bool) ($def['is_vocabulary_only'] ?? false);
            $this->assertSame($isVocabulary, $role->is_vocabulary_only, "{$slug} has the wrong kind.");
            $this->assertSame($isVocabulary, $role->granted_count === 0, "{$slug}: kind and grants disagree.");
        }
    }

    /** The ten roles that predate this phase are all still ordinary roles. */
    public function test_the_pre_existing_ten_roles_are_untouched_and_still_grant(): void
    {
        $this->seeded();

        foreach (['employee', 'team_lead', 'senior_executive', 'project_manager', 'department_head',
                  'hr_recruiter', 'hr_executive', 'hiring_manager', 'accounts', 'director'] as $slug) {
            $role = StaffRole::where('tenant_id', $this->tenant->id)->where('slug', $slug)->firstOrFail();

            $this->assertFalse($role->is_vocabulary_only, "{$slug} must remain an ordinary role.");
            $this->assertGreaterThan(0, $role->granted_count, "{$slug} must still grant something.");
        }
    }

    /* ── hr and manager ───────────────────────────────────────────────── */

    /** @dataProvider vocabularyRoles */
    public function test_the_legacy_vocabulary_exists_as_a_staff_role(string $slug): void
    {
        $this->seeded();

        $role = StaffRole::where('tenant_id', $this->tenant->id)->where('slug', $slug)->first();

        $this->assertNotNull($role, "'{$slug}' is gated on by routes/sangoetrack.php and must be a legal name.");
        $this->assertTrue($role->is_vocabulary_only);
        $this->assertSame(DataScope::GLOBAL, $role->scope);
    }

    /**
     * The whole risk of this phase in one test: a vocabulary entry must not
     * become a way to be granted HR.
     *
     * @dataProvider vocabularyRoles
     */
    public function test_a_vocabulary_role_grants_absolutely_nothing(string $slug): void
    {
        $this->seeded();

        $role = StaffRole::where('tenant_id', $this->tenant->id)->where('slug', $slug)->firstOrFail();

        $this->assertSame([], $role->grants(), "'{$slug}' must carry no permissions at all.");
        $this->assertSame(0, $role->granted_count);

        $holder = app(StaffRoleService::class)->assign($this->user('staff', "holder-{$slug}@arch.test"), $role);

        $this->assertFalse($holder->canManageHrQueue(),
            "Holding '{$slug}' must not confer HR authority — it is a name, not a grant.");
        $this->assertSame([], app(StaffRoleService::class)->effectiveGrants($holder->fresh()));
        $this->assertFalse(
            app(StaffPermissionService::class)->can($holder->fresh(), StaffPermission::VIEW_GLOBAL, 'hr_employees')
        );
    }

    /** `hr` is not a coarse synonym for hr_executive, and must not behave like one. */
    public function test_hr_is_not_a_shortcut_to_hr_executive(): void
    {
        $this->seeded();

        $hr         = StaffRole::where('tenant_id', $this->tenant->id)->where('slug', 'hr')->firstOrFail();
        $hrExecutive = StaffRole::where('tenant_id', $this->tenant->id)->where('slug', 'hr_executive')->firstOrFail();

        $this->assertSame(0, $hr->granted_count);
        $this->assertGreaterThan(0, $hrExecutive->granted_count);
    }

    public static function vocabularyRoles(): array
    {
        return ['hr' => ['hr'], 'manager' => ['manager']];
    }

    /* ── assignment ───────────────────────────────────────────────────── */

    public function test_assigning_an_ordinary_role_still_sets_internal_role(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $service->create($this->tenant->id, [
            'name' => 'Ops Lead', 'permissions' => ['tasks' => [StaffPermission::VIEW_OWN]],
        ]);

        $user = $service->assign($this->user('staff', 'ordinary@arch.test'), $role);

        $this->assertSame('ops_lead', $user->internal_role);
        $this->assertSame($role->id, $user->staff_role_id);
        $this->assertSame([StaffPermission::VIEW_OWN], $service->effectiveGrants($user->fresh())['tasks'] ?? null);
    }

    /**
     * A vocabulary-only role is assignable when an admin deliberately picks it —
     * that is the point of writing the name down — and it carries the slug into
     * internal_role exactly like any other role.
     */
    public function test_assigning_a_vocabulary_role_sets_internal_role_and_grants_nothing(): void
    {
        $this->seeded();
        $service = app(StaffRoleService::class);
        $manager = StaffRole::where('tenant_id', $this->tenant->id)->where('slug', 'manager')->firstOrFail();

        $user = $service->assign($this->user('staff', 'vocab@arch.test'), $manager);

        $this->assertSame('manager', $user->internal_role, 'Which is what the SangoeTrack gate reads.');
        $this->assertSame($manager->id, $user->staff_role_id);
        $this->assertFalse($user->canManageHrQueue());
    }

    /** Seeding must never touch a user. */
    public function test_seeding_does_not_backfill_any_user(): void
    {
        $a = $this->user('staff', 'untouched-a@arch.test');
        $b = $this->user('staff', 'untouched-b@arch.test', 'some_legacy_value');

        $this->seeded();

        $this->assertNull($a->fresh()->internal_role);
        $this->assertNull($a->fresh()->staff_role_id);
        $this->assertSame('some_legacy_value', $b->fresh()->internal_role,
            'An existing internal_role, even one no role defines, must be left exactly as it is.');
    }

    /** Running it twice adds nothing — it is the existing convention, unchanged. */
    public function test_seeding_is_idempotent(): void
    {
        $service = app(StaffRoleService::class);

        $first  = $service->ensureSeeded($this->tenant->id);
        $second = $service->ensureSeeded($this->tenant->id);

        $this->assertSame(count(StaffRoleTemplate::DEFINITIONS), $first);
        $this->assertSame(0, $second);
    }

    /* ── SangoeTrack: the gate this vocabulary exists for ─────────────── */

    /**
     * The middleware reads users.internal_role, NOT either table — so none of
     * this phase could change it, and these prove it did not.
     *
     * @dataProvider sangoeTrackActors
     */
    public function test_the_sangoetrack_gate_still_admits_and_refuses_exactly_as_before(
        string $accountType,
        ?string $internal,
        bool $allowed,
    ): void {
        $this->seeded();

        Sanctum::actingAs($this->user($accountType, "st-{$accountType}-{$internal}@arch.test", $internal));

        // routes/sangoetrack.php mounts the proxy under /hr/track.
        $status = $this->getJson('/api/hr/track/dashboard')->status();

        $allowed
            ? $this->assertNotSame(403, $status, "{$accountType}/{$internal} must still pass the gate.")
            : $this->assertSame(403, $status, "{$accountType}/{$internal} must still be refused.");
    }

    public static function sangoeTrackActors(): array
    {
        return [
            'admin'                 => ['admin', null, true],
            'staff with hr'         => ['staff', 'hr', true],
            'staff with manager'    => ['staff', 'manager', true],
            'staff with hr_executive' => ['staff', 'hr_executive', false],
            'staff with accounts'   => ['staff', 'accounts', false],
            'staff with nothing'    => ['staff', null, false],
            'client with hr'        => ['client', 'hr', false],
        ];
    }

    /* ── the retired path ─────────────────────────────────────────────── */

    /**
     * access_roles must not quietly become a second source of authority again.
     * The table is deliberately still there; what is gone is the way to write
     * to it and anything that reads it.
     */
    public function test_nothing_in_the_application_reads_or_writes_access_roles(): void
    {
        $appFiles = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $appFiles[] = $file->getPathname();
            }
        }

        $referencing = array_values(array_filter($appFiles, function ($path) {
            // The model itself is kept, documented as retired, so production
            // rows remain readable before anything is dropped.
            if (str_ends_with($path, 'Models/Access/AccessRole.php')) {
                return false;
            }

            return str_contains(file_get_contents($path), 'AccessRole');
        }));

        $this->assertSame([], $referencing,
            'Something wired access_roles back up: '.implode(', ', $referencing));
    }
}
