<?php

namespace Sire\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sire\Contracts\SireSettingsProvider;
use Sire\Support\SireReleaseClass;

/**
 * SIRE - seed the reference data the workflow cannot run without.
 *
 * WHY THIS EXISTS
 *
 * The migrations create sire_severities and sire_report_categories empty, and
 * nothing else fills them. That leaves a working install that cannot actually be
 * used: the `triage` transition REQUIRES severity_id, so with no severities every
 * report is stuck in `new` for ever and the engineering track is dead on arrival.
 *
 * It is deliberately a command rather than a migration. Rows are per TENANT, and
 * a migration cannot know which tenants exist -- nor should a schema change
 * invent business data. Run it again after adding a tenant.
 *
 * IDEMPOTENT. Matching is by (tenant_id, code), the unique key both tables
 * already declare, so re-running inserts only what is missing. Anything the team
 * has renamed, re-levelled or deactivated is left exactly as it is: this seeds,
 * it does not reset.
 */
class SireSeedDefaults extends Command
{
    protected $signature = 'sire:seed-defaults
                            {--tenant= : Only this tenant id (default: every tenant that has users)}';

    protected $description = 'SIRE: seed default severities and report categories per tenant.';

    /**
     * level 1 = lowest, the convention the schema comment sets.
     *
     * Targets are in minutes and deliberately loose. SLA is computed on read, so
     * a target set too tight produces noise, never a wrong state.
     */
    private const SEVERITIES = [
        ['code' => 's1', 'name' => 'S1 - Critical', 'level' => 4, 'color' => '#dc2626',
         'ack' => 15,   'triage' => 30,   'resolve' => 240,   'approval' => true,  'escalate' => true],
        ['code' => 's2', 'name' => 'S2 - High',     'level' => 3, 'color' => '#f97316',
         'ack' => 60,   'triage' => 120,  'resolve' => 1440,  'approval' => false, 'escalate' => true],
        ['code' => 's3', 'name' => 'S3 - Medium',   'level' => 2, 'color' => '#eab308',
         'ack' => 240,  'triage' => 480,  'resolve' => 4320,  'approval' => false, 'escalate' => false],
        ['code' => 's4', 'name' => 'S4 - Low',      'level' => 1, 'color' => '#64748b',
         'ack' => null, 'triage' => 1440, 'resolve' => 20160, 'approval' => false, 'escalate' => false],
    ];

    /**
     * release_class drives release-note grouping and the release gates, so every
     * category names one rather than leaving it to be guessed per issue.
     */
    private const CATEGORIES = [
        ['code' => 'functional',  'name' => 'Functional defect', 'class' => SireReleaseClass::BUG,
         'desc' => 'A feature does not do what it is supposed to do.', 'sev' => 's2', 'investigate' => true],
        ['code' => 'data',        'name' => 'Data issue', 'class' => SireReleaseClass::BUG,
         'desc' => 'Wrong, missing or duplicated data.', 'sev' => 's2', 'investigate' => true],
        ['code' => 'security',    'name' => 'Security', 'class' => SireReleaseClass::SECURITY,
         'desc' => 'Access control, data exposure, anything a customer must not see.', 'sev' => 's1', 'investigate' => true],
        ['code' => 'performance', 'name' => 'Performance', 'class' => SireReleaseClass::PERFORMANCE,
         'desc' => 'Slow pages, timeouts, queries that do not scale.', 'sev' => 's3', 'investigate' => true],
        ['code' => 'integration', 'name' => 'Integration', 'class' => SireReleaseClass::BUG,
         'desc' => 'Mail, WhatsApp, payments or another external system.', 'sev' => 's2', 'investigate' => true],
        ['code' => 'ui',          'name' => 'UI / cosmetic', 'class' => SireReleaseClass::BUG,
         'desc' => 'Layout, wording or styling. Nothing is computed wrongly.', 'sev' => 's4', 'investigate' => false],
        ['code' => 'change',      'name' => 'Change request', 'class' => SireReleaseClass::CHANGE,
         'desc' => 'It works as built, and the business wants it built differently.', 'sev' => 's3', 'investigate' => false],
        ['code' => 'improvement', 'name' => 'Improvement', 'class' => SireReleaseClass::IMPROVEMENT,
         'desc' => 'Nothing is broken; this would make it better.', 'sev' => 's4', 'investigate' => false],
    ];

    public function handle(): int
    {
        $tenants = $this->tenants();

        if ($tenants === []) {
            $this->warn('  No tenants found. Nothing to seed.');

            return self::SUCCESS;
        }

        $hasReleaseClass = Schema::hasColumn('sire_report_categories', 'release_class');

        foreach ($tenants as $tenantId) {
            $severityIds = [];
            $newSeverities = 0;

            foreach (self::SEVERITIES as $i => $s) {
                $existing = DB::table('sire_severities')
                    ->where('tenant_id', $tenantId)->where('code', $s['code'])->first();

                if ($existing !== null) {
                    $severityIds[$s['code']] = $existing->id;

                    continue;
                }

                $severityIds[$s['code']] = DB::table('sire_severities')->insertGetId([
                    'tenant_id'                 => $tenantId,
                    'code'                      => $s['code'],
                    'name'                      => $s['name'],
                    'level'                     => $s['level'],
                    'color'                     => $s['color'],
                    'ack_target_minutes'        => $s['ack'],
                    'triage_target_minutes'     => $s['triage'],
                    'resolve_target_minutes'    => $s['resolve'],
                    'requires_closure_approval' => $s['approval'],
                    'auto_escalate'             => $s['escalate'],
                    'sort_order'                => ($i + 1) * 10,
                    'is_active'                 => true,
                    'created_at'                => now(),
                    'updated_at'                => now(),
                ]);

                $newSeverities++;
            }

            $newCategories = 0;

            foreach (self::CATEGORIES as $i => $c) {
                $exists = DB::table('sire_report_categories')
                    ->where('tenant_id', $tenantId)->where('code', $c['code'])->exists();

                if ($exists) {
                    continue;
                }

                $row = [
                    'tenant_id'              => $tenantId,
                    'code'                   => $c['code'],
                    'name'                   => $c['name'],
                    'description'            => $c['desc'],
                    'default_severity_id'    => $severityIds[$c['sev']] ?? null,
                    'requires_investigation' => $c['investigate'],
                    'sort_order'             => ($i + 1) * 10,
                    'is_active'              => true,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ];

                if ($hasReleaseClass) {
                    $row['release_class'] = $c['class'];
                }

                DB::table('sire_report_categories')->insert($row);
                $newCategories++;
            }

            $rosters = $this->seedRosters($tenantId);

            $this->line(sprintf(
                '  tenant %-5s severities +%d   categories +%d   rosters %s',
                $tenantId,
                $newSeverities,
                $newCategories,
                $rosters,
            ));
        }

        $this->newLine();
        $this->info('  Reference data is in place. Reports can now be triaged.');

        return self::SUCCESS;
    }

    /**
     * Put the tenant's internal people on the engineering rosters.
     *
     * SIRE reads assignable users from three roster settings (leads, developers,
     * qa) rather than from a role name -- deliberately, because the CRM has no
     * developer/QA concept and guessing role names is what makes a picker empty
     * in a host that calls them something else.
     *
     * But UNSET rosters mean NO assignable users at all: the Assign form renders
     * an empty dropdown and the issue can never leave triage. So a fresh install
     * starts with every admin and internal user on all three, which is usable on
     * day one and is exactly the list an admin then narrows.
     *
     * Existing rosters are never touched -- narrowing one is the whole point.
     */
    private function seedRosters(int $tenantId): string
    {
        $settings = app(SireSettingsProvider::class);

        $roles = array_merge(
            (array) config('sire.login_types.admin', []),
            (array) config('sire.login_types.internal_user', []),
        );

        if ($roles === []) {
            return 'skipped (no internal roles mapped)';
        }

        $table     = (string) config('sire.user.table', 'users');
        $roleField = config('sire.user.role_field');
        $column    = (string) config('sire.tenant.attribute', 'tenant_id');

        $ids = DB::table($table)
            ->where($column, $tenantId)
            ->when($roleField, fn ($q) => $q->whereIn($roleField, $roles))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if ($ids === []) {
            return 'skipped (no internal users)';
        }

        $written = [];

        foreach (['leads', 'developers', 'qa'] as $roster) {
            $key = "sire.roles.{$roster}";

            if (filled($settings->get($tenantId, $key))) {
                continue;   // already curated -- leave it exactly as it is
            }

            $settings->set($tenantId, $key, $ids);
            $written[] = $roster;
        }

        return $written === [] ? 'already set' : implode('+', $written).' ('.count($ids).' users)';
    }

    /** @return list<int> */
    private function tenants(): array
    {
        if ($this->option('tenant')) {
            return [(int) $this->option('tenant')];
        }

        // Read tenants off the users table rather than a tenants table: SIRE does
        // not require the host to have one, and these are the exact table and
        // column the installer already confirmed.
        $table  = (string) config('sire.user.table', 'users');
        $column = (string) config('sire.tenant.attribute', 'tenant_id');

        if (! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(static fn ($v) => (int) $v)
            ->filter()
            ->values()
            ->all();
    }
}